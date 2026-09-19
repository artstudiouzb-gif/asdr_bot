<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Core\Db;
use App\Core\Logger;
use Throwable;

/**
 * Выполняет задания панели (таблица tg_commands).
 *
 * Панель сама к сессии Telegram не прикасается — только ставит команду;
 * выполняет её один процесс, cron-воркер.
 */
final class CommandRunner
{
    /** @return int сколько команд выполнено */
    public function runQueued(int $limit = 5): int
    {
        $done = 0;
        foreach (Db::all('SELECT * FROM tg_commands WHERE status = ? ORDER BY id LIMIT ' . max(1, $limit), ['queued']) as $command) {
            if (!$this->claim((int)$command['id'])) {
                continue;               // забрал другой процесс
            }
            $this->execute($command);
            $done++;
        }
        return $done;
    }

    private function claim(int $id): bool
    {
        return Db::run(
            'UPDATE tg_commands SET status = ? WHERE id = ? AND status = ?',
            ['running', $id, 'queued']
        )->rowCount() === 1;
    }

    private function execute(array $command): void
    {
        $id = (int)$command['id'];
        $accountId = (int)$command['account_id'];
        $payload = json_decode((string)($command['payload'] ?? '{}'), true) ?: [];
        $account = Db::one('SELECT * FROM tg_accounts WHERE id = ?', [$accountId]);

        if ($account === null) {
            $this->finish($id, 'error', 'Аккаунт удалён');
            return;
        }

        $sessionPath = (string)($account['session_path'] ?? '') !== ''
            ? (string)$account['session_path']
            : MtprotoClient::sessionPathFor((string)$account['label']);
        $client = new MtprotoClient($sessionPath);

        try {
            $result = match ((string)$command['command']) {
                'login_start'    => $this->loginStart($client, $account, (string)($payload['phone'] ?? ''), $sessionPath),
                'login_code'     => $this->applyStatus($client, $accountId, $client->submitCode((string)($payload['code'] ?? ''))),
                'login_password' => $this->applyStatus($client, $accountId, $client->submitPassword((string)($payload['password'] ?? ''))),
                'logout'         => $this->logout($client, $accountId),
                'test_peer',
                'resolve_peer'   => $this->resolvePeer($client, $payload),
                default          => throw new \RuntimeException('Неизвестная команда'),
            };
            $this->finish($id, 'done', $result);
        } catch (Throwable $e) {
            $message = $this->humanError($e);
            Db::update('tg_accounts', ['last_error' => $message, 'last_check_at' => Db::now()],
                'id = :id', ['id' => $accountId]);
            Logger::error('telegram', 'Команда ' . $command['command'] . ' не выполнена: ' . $message);
            $this->finish($id, 'error', $message);
        } finally {
            $client->close();
        }
    }

    private function loginStart(MtprotoClient $client, array $account, string $phone, string $sessionPath): string
    {
        if ($phone === '') {
            throw new \RuntimeException('Не указан номер телефона');
        }
        $status = $client->startLogin($phone);
        Db::update('tg_accounts', [
            'phone'         => $phone,
            'session_path'  => $sessionPath,
            'status'        => $status,
            'last_error'    => null,
            'last_check_at' => Db::now(),
        ], 'id = :id', ['id' => (int)$account['id']]);

        return $status === 'awaiting_code'
            ? 'Код отправлен в Telegram — введите его в панели'
            : 'Состояние: ' . $status;
    }

    private function applyStatus(MtprotoClient $client, int $accountId, string $status): string
    {
        $data = ['status' => $status, 'last_error' => null, 'last_check_at' => Db::now()];
        $message = match ($status) {
            'active'            => 'Аккаунт подключён',
            'awaiting_password' => 'Нужен пароль двухфакторной защиты',
            'awaiting_code'     => 'Нужен код из Telegram',
            default             => 'Состояние: ' . $status,
        };
        if ($status === 'active') {
            $self = $client->self();
            $data['username'] = $self['username'];
            $message = 'Аккаунт подключён: ' . $self['name']
                . ($self['username'] !== null ? ' (@' . $self['username'] . ')' : '');
        }
        Db::update('tg_accounts', $data, 'id = :id', ['id' => $accountId]);
        return $message;
    }

    private function logout(MtprotoClient $client, int $accountId): string
    {
        try {
            $client->logout();
        } catch (Throwable) {
            // сессия могла быть уже недействительна — всё равно чистим у себя
        }
        $account = Db::one('SELECT session_path FROM tg_accounts WHERE id = ?', [$accountId]);
        $path = (string)($account['session_path'] ?? '');
        if ($path !== '' && is_dir($path)) {
            $this->removeDirectory($path);
        }
        Db::update('tg_accounts', ['status' => 'new', 'username' => null, 'last_check_at' => Db::now()],
            'id = :id', ['id' => $accountId]);
        return 'Аккаунт отключён, файл сессии удалён';
    }

    private function resolvePeer(MtprotoClient $client, array $payload): string
    {
        $peer = Peer::normalize((string)($payload['peer'] ?? ''));
        if ($peer === '') {
            throw new \RuntimeException('Не указан канал');
        }
        $info = $client->resolve($peer);

        if (isset($payload['source_id'])) {
            Db::update('sources', ['tg_peer_id' => $info['peer_id'], 'last_error' => null],
                'id = :id', ['id' => (int)$payload['source_id']]);
        }
        if (isset($payload['destination_id'])) {
            Db::update('destinations', ['tg_peer_id' => $info['peer_id'], 'last_error' => null],
                'id = :id', ['id' => (int)$payload['destination_id']]);
        }
        return sprintf('Канал найден: %s (id %d, тип %s)', $info['title'], $info['peer_id'], $info['type']);
    }

    /** Секреты не должны оставаться в базе после выполнения. */
    private function finish(int $id, string $status, string $result): void
    {
        Db::update('tg_commands', [
            'status'      => $status,
            'result'      => mb_substr($result, 0, 1000),
            'payload'     => null,
            'executed_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);
    }

    private function humanError(Throwable $e): string
    {
        $message = $e->getMessage();
        return match (true) {
            str_contains($message, 'PHONE_CODE_INVALID')   => 'Неверный код из Telegram',
            str_contains($message, 'PHONE_CODE_EXPIRED')   => 'Код устарел, запросите новый',
            str_contains($message, 'PASSWORD_HASH_INVALID')=> 'Неверный пароль двухфакторной защиты',
            str_contains($message, 'PHONE_NUMBER_INVALID') => 'Неверный номер телефона',
            str_contains($message, 'USERNAME_NOT_OCCUPIED'),
            str_contains($message, 'USERNAME_INVALID')     => 'Канал с таким адресом не найден',
            str_contains($message, 'CHANNEL_PRIVATE')      => 'Канал приватный: аккаунт должен быть подписан на него',
            str_contains($message, 'FLOOD_WAIT')           => 'Telegram просит подождать: ' . $message,
            default                                        => mb_substr($message, 0, 400),
        };
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : @unlink($file);
        }
        @rmdir($path);
    }
}
