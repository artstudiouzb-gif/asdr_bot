<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Core\Env;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\Logger as MadelineLogger;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\Settings;

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
     * Новые сообщения канала после $minId, от старых к новым.
     *
     * @return array<int, Message>
     */
    public function history(string|int $peer, int $minId, int $limit): array
    {
        $response = $this->api()->messages->getHistory([
            'peer'        => $peer,
            'offset_id'   => 0,
            'offset_date' => 0,
            'add_offset'  => 0,
            'limit'       => max(1, min($limit, 100)),
            'max_id'      => 0,
            'min_id'      => max(0, $minId),
            'hash'        => 0,
        ]);

        $messages = [];
        foreach (array_reverse($response['messages'] ?? []) as $raw) {
            if (($raw['_'] ?? '') !== 'message') {
                continue;               // служебные события канала не переносим
            }
            $wrapped = $this->api()->wrapMessage($raw);
            if ($wrapped instanceof Message) {
                $messages[] = $wrapped;
            }
        }
        return $messages;
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
     */
    public function sendMedia(string|int $peer, Message $source, string $html, bool $silent = false): int
    {
        $media = $source->media;
        if ($media === null) {
            return $this->sendText($peer, $html, $silent);
        }
        $method = match (true) {
            $media instanceof \danog\MadelineProto\EventHandler\Media\Photo => 'sendPhoto',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Video => 'sendVideo',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Gif   => 'sendGif',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Audio => 'sendAudio',
            $media instanceof \danog\MadelineProto\EventHandler\Media\Voice => 'sendVoice',
            default                                                         => 'sendDocument',
        };

        return $this->api()->{$method}(
            peer: $peer,
            file: $media,
            caption: $html,
            parseMode: ParseMode::HTML,
            silent: $silent,
        )->id;
    }

    public function close(): void
    {
        $this->api = null;
    }
}
