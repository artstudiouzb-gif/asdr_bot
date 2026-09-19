<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Db;
use App\Core\Logger;
use App\Services\Processing\TextPipeline;
use App\Services\Telegram\Peer;
use danog\MadelineProto\StrTools;
use Throwable;

/** Обрабатывает очередь: очистка текста, подпись, отправка в целевой канал. */
final class Publisher
{
    private const BACKOFF_MINUTES = [1, 5, 15, 60];
    private const STALE_MINUTES = 10;

    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly int $maxAttempts = 4,
    ) {
    }

    /** @return array{published:int, skipped:int, errors:int} */
    public function run(float $deadline, int $batch = 20): array
    {
        $this->recoverStale();
        $counters = ['published' => 0, 'skipped' => 0, 'errors' => 0];
        $rateCache = [];

        foreach ($this->due($batch) as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $destinationId = (int)$row['destination_id'];
            $limit = (int)$row['rate_limit_per_min'];
            $rateCache[$destinationId] ??= $this->publishedLastMinute($destinationId);
            if ($rateCache[$destinationId] >= $limit) {
                continue;                       // лимит канала — подождёт следующего запуска
            }
            if (!$this->claim((int)$row['id'])) {
                continue;
            }

            try {
                $outcome = $this->publish($row);
                $counters[$outcome]++;
                if ($outcome === 'published') {
                    $rateCache[$destinationId]++;
                }
            } catch (Throwable $e) {
                $counters['errors']++;
                $this->handleFailure($row, $e);
            }
        }
        return $counters;
    }

    /** @return array<int, array<string, mixed>> */
    private function due(int $batch): array
    {
        return Db::all(
            "SELECT p.id, p.message_id, p.route_id, p.destination_id, p.attempts,
                    m.source_id, m.source_message_id, m.raw_text, m.raw_entities, m.media, m.media_kind, m.is_forward,
                    s.tg_identifier AS source_peer, s.account_id AS source_account_id, s.name AS source_name,
                    d.tg_identifier AS destination_peer, d.account_id AS destination_account_id,
                    d.publish_as, d.rate_limit_per_min, d.name AS destination_name,
                    r.rule_set_id, r.signature_id, r.filter_set_id, r.media_mode
             FROM publications p
             JOIN messages m ON m.id = p.message_id
             JOIN sources s ON s.id = m.source_id
             JOIN routes r ON r.id = p.route_id
             JOIN destinations d ON d.id = p.destination_id
             WHERE p.status IN ('NEW','RETRY') AND p.scheduled_at <= ? AND r.is_active = 1 AND d.is_active = 1
             ORDER BY p.scheduled_at, p.id
             LIMIT " . max(1, $batch),
            [Db::now()]
        );
    }

    private function claim(int $publicationId): bool
    {
        return Db::run(
            "UPDATE publications SET status = 'PROCESSING', attempts = attempts + 1, processed_at = ?
             WHERE id = ? AND status IN ('NEW','RETRY')",
            [Db::now(), $publicationId]
        )->rowCount() === 1;
    }

    /** @return string published|skipped */
    private function publish(array $row): string
    {
        $entities = json_decode((string)($row['raw_entities'] ?? '[]'), true) ?: [];
        $media = json_decode((string)($row['media'] ?? '[]'), true) ?: [];
        $sourceHtml = StrTools::entitiesToHtml((string)($row['raw_text'] ?? ''), $entities);

        $mediaMode = (string)$row['media_mode'];
        $hasMedia = $media !== [] && $mediaMode !== 'text_only';

        $result = TextPipeline::forRoute($row)->process($sourceHtml, $hasMedia, (int)$row['is_forward'] === 1);
        if ($result['skip'] !== null) {
            return $this->markSkipped($row, $result['skip']);
        }
        if ($mediaMode === 'skip_media' && $media !== []) {
            return $this->markSkipped($row, 'маршрут пропускает посты с медиа');
        }

        $destinationPeer = Peer::normalize((string)$row['destination_peer']);
        $client = $this->clients->forAccount(
            $row['destination_account_id'] === null ? null : (int)$row['destination_account_id']
        );

        if (!$hasMedia) {
            $destinationMessageId = $client->sendText($destinationPeer, $result['html']);
        } else {
            $sourceClient = $this->clients->forAccount(
                $row['source_account_id'] === null ? null : (int)$row['source_account_id']
            );
            $ids = array_map(static fn(array $item): int => (int)$item['id'], $media);
            $fresh = $sourceClient->messagesByIds(Peer::normalize((string)$row['source_peer']), $ids);
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($fresh[$id])) {
                    $ordered[] = $fresh[$id];
                }
            }
            if ($ordered === []) {
                // медиа удалили в источнике — публикуем хотя бы текст
                $destinationMessageId = $client->sendText($destinationPeer, $result['html']);
            } else {
                $destinationMessageId = count($ordered) > 1
                    ? $client->sendAlbum($destinationPeer, $ordered, $result['html'])
                    : $client->sendMedia($destinationPeer, $ordered[0], $result['html']);
            }
        }

        Db::update('publications', [
            'status'          => 'PUBLISHED',
            'processed_text'  => $result['html'],
            'dest_message_id' => $destinationMessageId,
            'published_at'    => Db::now(),
            'last_error'      => null,
            'skip_reason'     => null,
        ], 'id = :id', ['id' => (int)$row['id']]);

        Logger::info('publish', sprintf('«%s» #%d → «%s» #%d',
            $row['source_name'], (int)$row['source_message_id'], $row['destination_name'], $destinationMessageId), [
            'publication_id' => (int)$row['id'],
            'source_id'      => (int)$row['source_id'],
            'destination_id' => (int)$row['destination_id'],
            'rules'          => $result['applied'],
        ]);

        return 'published';
    }

    private function markSkipped(array $row, string $reason): string
    {
        Db::update('publications', [
            'status'      => 'SKIPPED',
            'skip_reason' => mb_substr($reason, 0, 255),
            'processed_at'=> Db::now(),
        ], 'id = :id', ['id' => (int)$row['id']]);
        Logger::info('publish', 'Пропущено: ' . $reason, [
            'publication_id' => (int)$row['id'], 'source_id' => (int)$row['source_id'],
        ]);
        return 'skipped';
    }

    private function handleFailure(array $row, Throwable $e): void
    {
        $message = mb_substr($e->getMessage(), 0, 500);
        $attempts = (int)$row['attempts'] + 1;

        if (preg_match('/FLOOD_WAIT_(\d+)/', $message, $match) === 1) {
            $seconds = (int)$match[1];
            Db::update('publications', [
                'status'       => 'RETRY',
                'scheduled_at' => gmdate('Y-m-d H:i:s', time() + $seconds + 1),
                'last_error'   => 'Telegram просит подождать ' . $seconds . ' с',
            ], 'id = :id', ['id' => (int)$row['id']]);
            Logger::warning('publish', 'FLOOD_WAIT ' . $seconds . ' с', [
                'publication_id' => (int)$row['id'], 'destination_id' => (int)$row['destination_id'],
            ]);
            return;
        }

        $exhausted = $attempts >= $this->maxAttempts;
        $backoff = self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1];
        Db::update('publications', [
            'status'       => $exhausted ? 'ERROR' : 'RETRY',
            'scheduled_at' => gmdate('Y-m-d H:i:s', time() + $backoff * 60),
            'last_error'   => $message,
        ], 'id = :id', ['id' => (int)$row['id']]);

        Logger::error('publish', ($exhausted ? 'Ошибка (попытки исчерпаны): ' : 'Ошибка, повторим: ') . $message, [
            'publication_id' => (int)$row['id'],
            'source_id'      => (int)$row['source_id'],
            'destination_id' => (int)$row['destination_id'],
        ]);
    }

    /** Процесс мог быть убит хостингом — возвращаем зависшие публикации в очередь. */
    private function recoverStale(): void
    {
        Db::run(
            "UPDATE publications SET status = 'RETRY'
             WHERE status = 'PROCESSING' AND processed_at < ?",
            [gmdate('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60)]
        );
    }

    private function publishedLastMinute(int $destinationId): int
    {
        return (int)Db::value(
            "SELECT COUNT(*) FROM publications
             WHERE destination_id = ? AND status = 'PUBLISHED' AND published_at > ?",
            [$destinationId, gmdate('Y-m-d H:i:s', time() - 60)]
        );
    }
}
