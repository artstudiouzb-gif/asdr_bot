<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Services\Telegram\Peer;

final class SourcesController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        $editId = $this->request->int('edit');
        return $this->view('sources', [
            'title'    => 'Источники',
            'sources'  => Db::all(
                'SELECT s.*, a.label AS account_label,
                        (SELECT COUNT(*) FROM routes r WHERE r.source_id = s.id AND r.is_active = 1) AS routes_count
                 FROM sources s LEFT JOIN tg_accounts a ON a.id = s.account_id ORDER BY s.id'
            ),
            'accounts' => Db::all("SELECT * FROM tg_accounts WHERE status = 'active' ORDER BY label"),
            'edit'     => $editId > 0 ? Db::one('SELECT * FROM sources WHERE id = ?', [$editId]) : null,
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
        $accountId = $this->request->int('account_id');

        if ($name === '' || !Peer::isValid($identifier)) {
            return $this->back('/sources', '', 'Укажите название и корректный адрес канала (@name, ссылку t.me или числовой id)');
        }
        $peer = (string)Peer::normalize($identifier);
        $duplicate = Db::one('SELECT id FROM sources WHERE tg_identifier = ? AND id <> ?', [$peer, $id]);
        if ($duplicate !== null) {
            return $this->back('/sources', '', 'Такой источник уже добавлен');
        }

        $data = [
            'name'          => $name,
            'tg_identifier' => $peer,
            'reader'        => 'mtproto',
            'account_id'    => $accountId > 0 ? $accountId : null,
            'fetch_limit'   => max(1, min($this->request->int('fetch_limit', 20), 100)),
            'is_active'     => $this->request->bool('is_active') ? 1 : 0,
        ];

        if ($id > 0) {
            Db::update('sources', $data, 'id = :id', ['id' => $id]);
            $message = 'Источник обновлён';
        } else {
            $data['created_at'] = Db::now();
            $id = Db::insert('sources', $data);
            $message = 'Источник добавлен. Нажмите «Проверить», чтобы убедиться в доступе';
        }
        return $this->back('/sources', $message);
    }

    public function test(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        $source = Db::one('SELECT * FROM sources WHERE id = ?', [(int)$params['id']]);
        if ($source === null) {
            return $this->back('/sources', '', 'Источник не найден');
        }
        $accountId = (int)($source['account_id'] ?? 0)
            ?: (int)(Db::value("SELECT id FROM tg_accounts WHERE status = 'active' ORDER BY id LIMIT 1") ?? 0);
        if ($accountId === 0) {
            return $this->back('/sources', '', 'Нет подключённого аккаунта Telegram — подключите его на странице «Аккаунты»');
        }
        $this->queueCommand($accountId, 'test_peer', [
            'peer' => $source['tg_identifier'], 'source_id' => (int)$source['id'],
        ]);
        return $this->back('/sources', 'Проверка поставлена в очередь — результат появится на странице «Аккаунты» через минуту');
    }

    public function toggle(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        Db::run('UPDATE sources SET is_active = 1 - is_active WHERE id = ?', [(int)$params['id']]);
        return $this->back('/sources', 'Статус изменён');
    }

    public function delete(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        Db::delete('sources', 'id = ?', [(int)$params['id']]);
        return $this->back('/sources', 'Источник удалён вместе с его маршрутами и историей');
    }
}
