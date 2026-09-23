<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Db;
use App\Core\Logger;
use App\Services\Telegram\Peer;
use Throwable;

/** Забирает новые посты источников в очередь публикаций. */
final class Ingestor
{
    public function __construct(private readonly ClientRegistry $clients)
    {
    }

    /** @return int сколько постов добавлено в очередь */
    public function run(float $deadline): int
    {
        $added = 0;
        foreach (Db::all('SELECT * FROM sources WHERE is_active = 1 ORDER BY id') as $source) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $added += $this->ingestSource($source);
                Db::update('sources', ['last_checked_at' => Db::now(), 'last_error' => null],
                    'id = :id', ['id' => (int)$source['id']]);
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 400);
                Db::update('sources', ['last_checked_at' => Db::now(), 'last_error' => $message],
                    'id = :id', ['id' => (int)$source['id']]);
                Logger::error('ingest', 'Источник «' . $source['name'] . '»: ' . $message,
                    ['source_id' => (int)$source['id']]);
            }
        }
        return $added;
    }

    /** Альбом в Telegram — не больше 10 файлов, меньше порция не имеет смысла. */
    private const MIN_BATCH = 10;

    private function ingestSource(array $source): int
    {
        $client = $this->clients->forAccount($source['account_id'] === null ? null : (int)$source['account_id']);
        $peer = Peer::normalize((string)$source['tg_identifier']);
        $sourceId = (int)$source['id'];
        $lastId = (int)$source['last_message_id'];

        if ($lastId === 0) {
            $lastId = $this->startCursor($client, $peer, $sourceId);
            if ($lastId === 0) {
                return 0;                       // канал пуст или только что подключён
            }
        }

        $batch = max((int)$source['fetch_limit'], self::MIN_BATCH);
        $messages = self::holdBackTrailingAlbum($client->history($peer, $lastId, $batch), $batch);
        if ($messages === []) {
            return 0;
        }

        $routes = Db::all('SELECT * FROM routes WHERE source_id = ? AND is_active = 1', [$sourceId]);
        $added = 0;
        $maxId = $lastId;

        foreach ($this->groupAlbums($messages) as $group) {
            $maxId = max($maxId, (int)end($group)['id']);
            $messageId = $this->storeMessage($sourceId, $group);
            if ($messageId === null) {
                continue;                       // уже забирали раньше
            }
            $added++;
            foreach ($routes as $route) {
                $this->queuePublication($messageId, $route);
            }
        }

        $this->moveCursor($sourceId, $maxId);
        return $added;
    }

    /**
     * Первое подключение источника: публикуем начиная с текущего момента,
     * а не всю историю канала. Настройка backfill_on_first_run позволяет
     * забрать N последних постов.
     */
    private function startCursor(\App\Services\Telegram\MtprotoClient $client, string|int $peer, int $sourceId): int
    {
        $backfill = max(0, min(\App\Core\Settings::int('backfill_on_first_run'), 50));
        $latest = $client->latest($peer, max(1, $backfill));
        if ($latest === []) {
            return 0;
        }

        if ($backfill === 0) {
            $newest = (int)end($latest)['id'];
            $this->moveCursor($sourceId, $newest);
            Logger::info('ingest', 'Источник подключён, публикуем посты новее #' . $newest,
                ['source_id' => $sourceId]);
            return 0;
        }

        $start = max(0, (int)$latest[0]['id'] - 1);
        $this->moveCursor($sourceId, $start);
        Logger::info('ingest', 'Источник подключён, забираем последние ' . count($latest) . ' постов',
            ['source_id' => $sourceId]);
        return $start;
    }

    private function moveCursor(int $sourceId, int $messageId): void
    {
        Db::run('UPDATE sources SET last_message_id = ? WHERE id = ? AND last_message_id < ?',
            [$messageId, $sourceId, $messageId]);
    }

    /**
     * Если порция заполнена целиком и заканчивается альбомом, его хвост мог не
     * поместиться. Такой альбом откладываем до следующего запуска целиком,
     * иначе он ушёл бы в канал двумя половинами.
     *
     * @param array<int, array<string, mixed>> $messages по возрастанию id
     * @return array<int, array<string, mixed>>
     */
    public static function holdBackTrailingAlbum(array $messages, int $requested): array
    {
        if ($messages === [] || count($messages) < $requested) {
            return $messages;                   // порция не полная — альбом точно целый
        }
        $lastGroup = $messages[count($messages) - 1]['grouped_id'] ?? null;
        if ($lastGroup === null) {
            return $messages;
        }
        $kept = $messages;
        while ($kept !== [] && ($kept[count($kept) - 1]['grouped_id'] ?? null) === $lastGroup) {
            array_pop($kept);
        }
        // вся порция — один альбом: он уже полный (больше 10 файлов не бывает)
        return $kept === [] ? $messages : $kept;
    }

    /**
     * Сообщения альбома приходят по одному — склеиваем обратно в один пост.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function groupAlbums(array $messages): array
    {
        $groups = [];
        $index = [];
        foreach ($messages as $message) {
            $groupId = $message['grouped_id'] ?? null;
            if ($groupId === null) {
                $groups[] = [$message];
                continue;
            }
            if (isset($index[$groupId])) {
                $groups[$index[$groupId]][] = $message;
                continue;
            }
            $index[$groupId] = count($groups);
            $groups[] = [$message];
        }
        return $groups;
    }

    /** @param array<int, array<string, mixed>> $group */
    private function storeMessage(int $sourceId, array $group): ?int
    {
        $head = $group[0];
        foreach ($group as $message) {
            if (trim((string)($message['message'] ?? '')) !== '') {
                $head = $message;
                break;
            }
        }

        $media = [];
        foreach ($group as $message) {
            $kind = $this->mediaKind($message);
            if ($kind !== 'none') {
                $media[] = ['id' => (int)$message['id'], 'type' => $kind];
            }
        }
        $text = (string)($head['message'] ?? '');

        $inserted = Db::run(
            'INSERT IGNORE INTO messages
                (source_id, source_message_id, grouped_id, posted_at, raw_text, raw_entities, media,
                 media_kind, is_forward, content_hash, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sourceId,
                (int)$group[0]['id'],
                isset($head['grouped_id']) ? (int)$head['grouped_id'] : null,
                isset($head['date']) ? gmdate('Y-m-d H:i:s', (int)$head['date']) : null,
                $text,
                json_encode($head['entities'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($media, JSON_UNESCAPED_UNICODE),
                count($media) > 1 ? 'album' : ($media[0]['type'] ?? 'none'),
                isset($head['fwd_from']) ? 1 : 0,
                $text === '' ? null : hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text)),
                Db::now(),
            ]
        )->rowCount();

        return $inserted === 1 ? (int)Db::pdo()->lastInsertId() : null;
    }

    private function queuePublication(int $messageId, array $route): void
    {
        Db::run(
            'INSERT IGNORE INTO publications
                (message_id, route_id, destination_id, status, scheduled_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $messageId,
                (int)$route['id'],
                (int)$route['destination_id'],
                'NEW',
                gmdate('Y-m-d H:i:s', time() + (int)$route['delay_seconds']),
                Db::now(),
            ]
        );
    }

    private function mediaKind(array $message): string
    {
        $media = $message['media'] ?? null;
        if (!is_array($media)) {
            return 'none';
        }
        return match ($media['_'] ?? '') {
            'messageMediaPhoto'    => 'photo',
            'messageMediaDocument' => $this->documentKind($media),
            'messageMediaWebPage'  => 'none',          // предпросмотр ссылки — не медиа
            default                => 'other',
        };
    }

    private function documentKind(array $media): string
    {
        foreach ($media['document']['attributes'] ?? [] as $attribute) {
            $type = $attribute['_'] ?? '';
            if ($type === 'documentAttributeAnimated') {
                return 'animation';
            }
            if ($type === 'documentAttributeVideo') {
                return 'video';
            }
            if ($type === 'documentAttributeAudio') {
                return 'audio';
            }
        }
        return 'document';
    }
}
