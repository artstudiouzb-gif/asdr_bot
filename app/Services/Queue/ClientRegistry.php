<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Db;
use App\Services\Telegram\MtprotoClient;
use RuntimeException;

/** Один запуск воркера — одно подключение на аккаунт (вход стоит времени). */
final class ClientRegistry
{
    /** @var array<int, MtprotoClient> */
    private array $clients = [];
    private ?int $defaultAccountId = null;

    public function forAccount(?int $accountId): MtprotoClient
    {
        $accountId = $accountId ?: $this->defaultAccountId();
        if (isset($this->clients[$accountId])) {
            return $this->clients[$accountId];
        }
        $account = Db::one('SELECT * FROM tg_accounts WHERE id = ?', [$accountId]);
        if ($account === null || $account['status'] !== 'active') {
            throw new RuntimeException('Аккаунт Telegram не подключён — откройте раздел «Аккаунты»');
        }
        $path = (string)($account['session_path'] ?? '') !== ''
            ? (string)$account['session_path']
            : MtprotoClient::sessionPathFor((string)$account['label']);

        return $this->clients[$accountId] = new MtprotoClient($path);
    }

    public function defaultAccountId(): int
    {
        if ($this->defaultAccountId !== null) {
            return $this->defaultAccountId;
        }
        $id = Db::value("SELECT id FROM tg_accounts WHERE status = 'active' AND kind = 'user' ORDER BY id LIMIT 1");
        if ($id === null) {
            throw new RuntimeException('Нет подключённого аккаунта Telegram');
        }
        return $this->defaultAccountId = (int)$id;
    }

    public function closeAll(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
    }
}
