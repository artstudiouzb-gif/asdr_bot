<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;

/** Маршруты: какой источник в какое назначение публикуется. */
final class RoutesController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        $editId = $this->request->int('edit');
        return $this->view('routes', [
            'title'        => 'Маршруты',
            'routes'       => Db::all(
                'SELECT r.*, s.name AS source_name, s.tg_identifier AS source_peer,
                        d.name AS destination_name, d.tg_identifier AS destination_peer,
                        sig.name AS signature_name, rs.name AS rule_set_name, fs.name AS filter_set_name
                 FROM routes r
                 JOIN sources s ON s.id = r.source_id
                 JOIN destinations d ON d.id = r.destination_id
                 LEFT JOIN signatures sig ON sig.id = r.signature_id
                 LEFT JOIN rule_sets rs ON rs.id = r.rule_set_id
                 LEFT JOIN filter_sets fs ON fs.id = r.filter_set_id
                 ORDER BY s.name, d.name'
            ),
            'sources'      => Db::all('SELECT id, name FROM sources ORDER BY name'),
            'destinations' => Db::all('SELECT id, name FROM destinations ORDER BY name'),
            'ruleSets'     => Db::all('SELECT id, name FROM rule_sets ORDER BY name'),
            'signatures'   => Db::all('SELECT id, name FROM signatures ORDER BY name'),
            'filterSets'   => Db::all('SELECT id, name FROM filter_sets ORDER BY name'),
            'edit'         => $editId > 0 ? Db::one('SELECT * FROM routes WHERE id = ?', [$editId]) : null,
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
        $sourceId = $this->request->int('source_id');
        $destinationId = $this->request->int('destination_id');
        if ($sourceId <= 0 || $destinationId <= 0) {
            return $this->back('/routes', '', 'Выберите источник и назначение');
        }
        $existing = Db::one('SELECT id FROM routes WHERE source_id = ? AND destination_id = ? AND id <> ?',
            [$sourceId, $destinationId, $id]);
        if ($existing !== null) {
            return $this->back('/routes', '', 'Такой маршрут уже есть');
        }

        $optional = static fn(int $value): ?int => $value > 0 ? $value : null;
        $data = [
            'source_id'      => $sourceId,
            'destination_id' => $destinationId,
            'delay_seconds'  => max(0, min($this->request->int('delay_seconds'), 86400)),
            'media_mode'     => in_array($this->request->input('media_mode'), ['all', 'text_only', 'skip_media'], true)
                ? $this->request->input('media_mode') : 'all',
            'rule_set_id'    => $optional($this->request->int('rule_set_id')),
            'signature_id'   => $optional($this->request->int('signature_id')),
            'filter_set_id'  => $optional($this->request->int('filter_set_id')),
            'is_active'      => $this->request->bool('is_active') ? 1 : 0,
        ];

        if ($id > 0) {
            Db::update('routes', $data, 'id = :id', ['id' => $id]);
            return $this->back('/routes', 'Маршрут обновлён');
        }
        $data['created_at'] = Db::now();
        Db::insert('routes', $data);
        return $this->back('/routes', 'Маршрут создан');
    }

    public function toggle(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        Db::run('UPDATE routes SET is_active = 1 - is_active WHERE id = ?', [(int)$params['id']]);
        return $this->back('/routes', 'Статус изменён');
    }

    public function delete(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        Db::delete('routes', 'id = ?', [(int)$params['id']]);
        return $this->back('/routes', 'Маршрут удалён');
    }
}
