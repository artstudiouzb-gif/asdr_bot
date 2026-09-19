<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Services\Telegram\MtprotoClient;

/** Подключение Telegram-аккаунта: телефон → код → пароль 2FA. */
final class AccountsController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        return $this->view('accounts', [
            'title'    => 'Аккаунты Telegram',
            'accounts' => Db::all('SELECT * FROM tg_accounts ORDER BY id'),
            'commands' => Db::all('SELECT * FROM tg_commands ORDER BY id DESC LIMIT 10'),
            'apiReady' => \App\Core\Env::get('TG_API_ID') !== null && \App\Core\Env::get('TG_API_HASH') !== null,
        ]);
    }

    public function store(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }

        $label = $this->request->input('label');
        $phone = preg_replace('/[^\d+]/', '', $this->request->input('phone')) ?? '';
        if ($label === '' || strlen($phone) < 8) {
            return $this->back('/accounts', '', 'Укажите название и номер телефона в международном формате');
        }
        if (Db::one('SELECT id FROM tg_accounts WHERE label = ?', [$label]) !== null) {
            return $this->back('/accounts', '', 'Аккаунт с таким названием уже есть');
        }

        $id = Db::insert('tg_accounts', [
            'label'        => $label,
            'kind'         => 'user',
            'phone'        => $phone,
            'session_path' => MtprotoClient::sessionPathFor($label),
            'status'       => 'new',
            'created_at'   => Db::now(),
        ]);
        $this->queueCommand($id, 'login_start', ['phone' => $phone]);

        return $this->back('/accounts', 'Запрос кода поставлен в очередь — воркер выполнит его в течение минуты');
    }

    public function code(array $params): Response
    {
        return $this->submit((int)$params['id'], 'login_code', 'code',
            preg_replace('/\D/', '', $this->request->input('code')) ?? '',
            'Код отправлен воркеру');
    }

    public function password(array $params): Response
    {
        return $this->submit((int)$params['id'], 'login_password', 'password',
            $this->request->raw('password'), 'Пароль отправлен воркеру');
    }

    public function logout(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        $this->queueCommand((int)$params['id'], 'logout');
        return $this->back('/accounts', 'Отключение поставлено в очередь');
    }

    public function delete(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        $id = (int)$params['id'];
        $used = (int)Db::value('SELECT COUNT(*) FROM sources WHERE account_id = ?', [$id])
            + (int)Db::value('SELECT COUNT(*) FROM destinations WHERE account_id = ?', [$id]);
        if ($used > 0) {
            return $this->back('/accounts', '', 'Аккаунт используется источниками или назначениями — сначала измените их');
        }
        $this->queueCommand($id, 'logout');
        return $this->back('/accounts', 'Аккаунт будет отключён; удалить запись можно после этого');
    }

    private function submit(int $accountId, string $command, string $field, string $value, string $message): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        if ($value === '') {
            return $this->back('/accounts', '', 'Поле пустое');
        }
        $this->queueCommand($accountId, $command, [$field => $value]);
        return $this->back('/accounts', $message . ' — обновите страницу через минуту');
    }
}
