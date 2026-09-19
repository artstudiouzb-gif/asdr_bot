<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Services\Telegram\Peer;

final class DestinationsController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        $editId = $this->request->int('edit');
        return $this->view('destinations', [
            'title'        => 'Назначения',
            'destinations' => Db::all(
                'SELECT d.*, a.label AS account_label,
                        (SELECT COUNT(*) FROM routes r WHERE r.destination_id = d.id AND r.is_active = 1) AS routes_count
                 FROM destinations d LEFT JOIN tg_accounts a ON a.id = d.account_id ORDER BY d.id'
            ),
            'accounts'     => Db::all("SELECT * FROM tg_accounts WHERE status = 'active' ORDER BY label"),
            'edit'         => $editId > 0 ? Db::one('SELECT * FROM destinations WHERE id = ?', [$editId]) : null,
        ]);
    }

    public function save(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }

        $id = $this->request->int('id');
        $name = $this->request->input('name');
        $identifier = $this->request->input('tg_identifier');
        if ($name === '' || !Peer::isValid($identifier)) {
            return $this->back('/destinations', '', 'Укажите название и корректный адрес канала');
        }
        $peer = (string)Peer::normalize($identifier);
        if (Db::one('SELECT id FROM destinations WHERE tg_identifier = ? AND id <> ?', [$peer, $id]) !== null) {
            return $this->back('/destinations', '', 'Такое назначение уже добавлено');
        }

        $accountId = $this->request->int('account_id');
        $data = [
            'name'               => $name,
            'tg_identifier'      => $peer,
            'publish_as'         => $this->request->input('publish_as') === 'bot' ? 'bot' : 'user',
            'account_id'         => $accountId > 0 ? $accountId : null,
            'rate_limit_per_min' => max(1, min($this->request->int('rate_limit_per_min', 15), 60)),
            'is_active'          => $this->request->bool('is_active') ? 1 : 0,
        ];

        if ($id > 0) {
            Db::update('destinations', $data, 'id = :id', ['id' => $id]);
            return $this->back('/destinations', 'Назначение обновлено');
        }
        $data['created_at'] = Db::now();
        Db::insert('destinations', $data);
        return $this->back('/destinations',
            'Назначение добавлено. Аккаунт должен быть админом канала, а «Подписывать сообщения» — выключено');
    }

    public function test(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        $destination = Db::one('SELECT * FROM destinations WHERE id = ?', [(int)$params['id']]);
        if ($destination === null) {
            return $this->back('/destinations', '', 'Назначение не найдено');
        }
        $accountId = (int)($destination['account_id'] ?? 0)
            ?: (int)(Db::value("SELECT id FROM tg_accounts WHERE status = 'active' ORDER BY id LIMIT 1") ?? 0);
        if ($accountId === 0) {
            return $this->back('/destinations', '', 'Нет подключённого аккаунта Telegram');
        }
        $this->queueCommand($accountId, 'test_peer', [
            'peer' => $destination['tg_identifier'], 'destination_id' => (int)$destination['id'],
        ]);
        return $this->back('/destinations', 'Проверка поставлена в очередь — результат на странице «Аккаунты»');
    }

    public function toggle(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        Db::run('UPDATE destinations SET is_active = 1 - is_active WHERE id = ?', [(int)$params['id']]);
        return $this->back('/destinations', 'Статус изменён');
    }

    public function delete(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        Db::delete('destinations', 'id = ?', [(int)$params['id']]);
        return $this->back('/destinations', 'Назначение удалено');
    }
}
