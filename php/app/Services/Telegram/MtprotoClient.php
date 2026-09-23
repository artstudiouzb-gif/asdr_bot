<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Core\Env;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\Logger as MadelineLogger;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\Settings;
use danog\MadelineProto\StrTools;

/**
 * Обёртка над MadelineProto.
 *
 * Работает в однопроцессном режиме: на шаред-хостинге proc_open запрещён,
 * поэтому фоновый IPC-процесс не используется — клиент живёт ровно столько,
 * сколько длится запуск воркера.
 *
 * Важно: с сессией работает ТОЛЬКО cron-воркер. Панель ставит задания
 * в таблицу tg_commands, иначе два процесса испортят файл авторизации.
 */
final class MtprotoClient
{
    private ?API $api = null;

    public function __construct(private readonly string $sessionPath)
    {
    }

    public static function sessionPathFor(string $label): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '_', $label) ?: 'account';
        return APP_ROOT . '/storage/telegram/' . $safe . '.madeline';
    }

    public function api(): API
    {
        if ($this->api instanceof API) {
            return $this->api;
        }
        $directory = dirname($this->sessionPath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Не создать каталог сессии: {$directory}");
        }

        $settings = new Settings();
        $settings->getAppInfo()
            ->setApiId((int)Env::require('TG_API_ID'))
            ->setApiHash(Env::require('TG_API_HASH'));
        $settings->getLogger()
            ->setType(MadelineLogger::FILE_LOGGER)
            ->setExtra(APP_ROOT . '/storage/logs/madeline.log')
            ->setLevel(MadelineLogger::LEVEL_WARNING)
            ->setMaxSize(2 * 1024 * 1024);
        $settings->getSerialization()->setInterval(30);
        $settings->getPeer()->setFullFetch(false)->setCacheAllPeersOnStartup(false);

        return $this->api = new API($this->sessionPath, $settings);
    }

    // ── авторизация ─────────────────────────────────────────────────────────
    /** Состояние в терминах поля tg_accounts.status. */
    public function status(): string
    {
        return match ($this->api()->getAuthorization()) {
            API::LOGGED_IN         => 'active',
            API::WAITING_CODE      => 'awaiting_code',
            API::WAITING_PASSWORD  => 'awaiting_password',
            API::WAITING_SIGNUP    => 'error',
            default                => 'new',
        };
    }

    public function startLogin(string $phone): string
    {
        $this->api()->phoneLogin($phone);
        return $this->status();
    }

    public function submitCode(string $code): string
    {
        $this->api()->completePhoneLogin($code);
        return $this->status();
    }

    public function submitPassword(string $password): string
    {
        $this->api()->complete2faLogin($password);
        return $this->status();
    }

    public function logout(): void
    {
        $this->api()->logout();
    }

    /** @return array{id:int, username:?string, name:string} */
    public function self(): array
    {
        $self = $this->api()->getSelf();
        if ($self === false) {
            throw new \RuntimeException('Аккаунт не авторизован');
        }
        $name = trim(((string)($self['first_name'] ?? '')) . ' ' . ((string)($self['last_name'] ?? '')));
        return [
            'id'       => (int)($self['id'] ?? 0),
            'username' => isset($self['username']) ? (string)$self['username'] : null,
            'name'     => $name !== '' ? $name : (string)($self['username'] ?? 'аккаунт'),
        ];
    }

    // ── каналы ──────────────────────────────────────────────────────────────
    /** @return array{peer_id:int, title:string, type:string} */
    public function resolve(string|int $peer): array
    {
        $info = $this->api()->getInfo($peer);
        if (!is_array($info)) {
            throw new \RuntimeException('Telegram не вернул сведений о канале');
        }
        $chat = $info['Chat'] ?? $info['User'] ?? [];
        return [
            'peer_id' => (int)($info['bot_api_id'] ?? 0),
            'title'   => (string)($chat['title'] ?? trim(((string)($chat['first_name'] ?? '')) . ' ' . ((string)($chat['last_name'] ?? '')))),
            'type'    => (string)($info['type'] ?? 'unknown'),
        ];
    }

    /**
     * Самые старые сообщения после $minId, по возрастанию id.
     *
     * Именно старые, а не последние: если с прошлого запуска вышло больше постов,
     * чем $limit, остаток заберёт следующий запуск, и ничего не потеряется.
     * offset_id = minId + 1 с отрицательным add_offset — стандартный способ Telegram
     * листать историю вперёд от известного сообщения.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(string|int $peer, int $minId, int $limit): array
    {
        $limit = max(1, min($limit, 100));
        $response = $this->api()->messages->getHistory([
            'peer'        => $peer,
            'offset_id'   => $minId + 1,
            'offset_date' => 0,
            'add_offset'  => -$limit,
            'limit'       => $limit,
            'max_id'      => 0,
            'min_id'      => max(0, $minId),
            'hash'        => 0,
        ]);
        return $this->onlyMessagesAscending($response['messages'] ?? []);
    }

    /**
     * Последние $limit сообщений канала — для первого подключения источника.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(string|int $peer, int $limit): array
    {
        $response = $this->api()->messages->getHistory([
            'peer'        => $peer,
            'offset_id'   => 0,
            'offset_date' => 0,
            'add_offset'  => 0,
            'limit'       => max(1, min($limit, 100)),
            'max_id'      => 0,
            'min_id'      => 0,
            'hash'        => 0,
        ]);
        return $this->onlyMessagesAscending($response['messages'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    private function onlyMessagesAscending(array $messages): array
    {
        $result = array_values(array_filter(
            $messages,
            static fn(array $raw): bool => ($raw['_'] ?? '') === 'message'   // служебные события не переносим
        ));
        usort($result, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        return $result;
    }

    /**
     * Свежие копии сообщений перед публикацией: ссылки на файлы в Telegram
     * живут недолго, поэтому медиа берём заново, а не из базы.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> сообщения по их id
     */
    public function messagesByIds(string|int $peer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $maxId = max($ids);
        $response = $this->api()->messages->getHistory([
            'peer'        => $peer,
            'offset_id'   => $maxId + 1,
            'offset_date' => 0,
            'add_offset'  => 0,
            'limit'       => max(count($ids), 10),
            'max_id'      => 0,
            'min_id'      => 0,
            'hash'        => 0,
        ]);

        $found = [];
        foreach ($response['messages'] ?? [] as $raw) {
            $id = (int)($raw['id'] ?? 0);
            if (($raw['_'] ?? '') === 'message' && in_array($id, $ids, true)) {
                $found[$id] = $raw;
            }
        }
        return $found;
    }

    // ── публикация ──────────────────────────────────────────────────────────
    public function sendText(string|int $peer, string $html, bool $silent = false): int
    {
        return $this->api()->sendMessage(
            peer: $peer,
            message: $html,
            parseMode: ParseMode::HTML,
            silent: $silent,
            noWebpage: true,
        )->id;
    }

    /**
     * Публикация медиа с новой подписью. Файл переиспользуется по ссылке
     * (без скачивания и повторной загрузки), поэтому ограничения в 50 МБ нет.
     *
     * @param array<string, mixed> $rawMessage сырое сообщение-источник
     */
    public function sendMedia(string|int $peer, array $rawMessage, string $html, bool $silent = false): int
    {
        $wrapped = $this->api()->wrapMessage($rawMessage);
        $media = $wrapped instanceof Message ? $wrapped->media : null;
        if ($media === null) {
            return $this->sendText($peer, $html, $silent);
        }

        $method = match (true) {
            $media instanceof \danog\MadelineProto\EventHandler\Media\Photo => 'sendPhoto',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Video => 'sendVideo',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Gif   => 'sendGif',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Audio => 'sendAudio',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Voice => 'sendVoice',
            default                                                           => 'sendDocument',
        };

        return $this->api()->{$method}(
            peer: $peer,
            file: $media,
            caption: $html,
            parseMode: ParseMode::HTML,
            silent: $silent,
        )->id;
    }

    /**
     * Альбом: все файлы уходят одной группой, подпись — на первом элементе,
     * порядок сохраняется. Если Telegram откажет в групповой отправке,
     * отправляем по одному, чтобы пост не потерялся.
     *
     * @param array<int, array<string, mixed>> $rawMessages сырые сообщения альбома по порядку
     */
    public function sendAlbum(string|int $peer, array $rawMessages, string $html, bool $silent = false): int
    {
        $withMedia = array_values(array_filter($rawMessages, static fn(array $m): bool => isset($m['media'])));
        if ($withMedia === []) {
            return $this->sendText($peer, $html, $silent);
        }
        if (count($withMedia) === 1) {
            return $this->sendMedia($peer, $withMedia[0], $html, $silent);
        }

        $parsed = StrTools::htmlToMessageEntities($html);
        $multiMedia = [];
        foreach (array_slice($withMedia, 0, 10) as $index => $raw) {
            $multiMedia[] = [
                '_'        => 'inputSingleMedia',
                'media'    => $raw['media'],
                'message'  => $index === 0 ? $parsed->message : '',
                'entities' => $index === 0
                    ? array_map(static fn($entity) => $entity->toMTProto(), $parsed->entities)
                    : [],
            ];
        }

        try {
            $updates = $this->api()->messages->sendMultiMedia([
                'peer'        => $peer,
                'multi_media' => $multiMedia,
                'silent'      => $silent,
            ]);
            return $this->firstMessageId($updates);
        } catch (\Throwable $e) {
            $firstId = $this->sendMedia($peer, $withMedia[0], $html, $silent);
            foreach (array_slice($withMedia, 1, 9) as $raw) {
                $this->sendMedia($peer, $raw, '', $silent);
            }
            return $firstId;
        }
    }

    /** Достаёт id первого созданного сообщения из ответа Telegram. */
    private function firstMessageId(array $updates): int
    {
        foreach ($updates['updates'] ?? [] as $update) {
            if (isset($update['message']['id'])) {
                return (int)$update['message']['id'];
            }
            if (isset($update['id']) && in_array($update['_'] ?? '', ['updateMessageID'], true)) {
                return (int)$update['id'];
            }
        }
        return 0;
    }

    public function close(): void
    {
        $this->api = null;
    }
}
