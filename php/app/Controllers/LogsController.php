<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;

/** Журнал публикаций: что, куда, когда и с каким результатом. */
final class LogsController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $conditions = ['1 = 1'];
        $params = [];

        $status = strtoupper($this->request->input('status'));
        if (in_array($status, ['NEW', 'PROCESSING', 'PUBLISHED', 'SKIPPED', 'ERROR', 'RETRY'], true)) {
            $conditions[] = 'p.status = ?';
            $params[] = $status;
        }
        $sourceId = $this->request->int('source_id');
        if ($sourceId > 0) {
            $conditions[] = 'm.source_id = ?';
            $params[] = $sourceId;
        }
        $destinationId = $this->request->int('destination_id');
        if ($destinationId > 0) {
            $conditions[] = 'p.destination_id = ?';
            $params[] = $destinationId;
        }
        $from = $this->request->input('from');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $conditions[] = 'p.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = $this->request->input('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $conditions[] = 'p.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $search = $this->request->int('message_id');
        if ($search > 0) {
            $conditions[] = '(m.source_message_id = ? OR p.dest_message_id = ?)';
            $params[] = $search;
            $params[] = $search;
        }

        $where = implode(' AND ', $conditions);
        $page = max(1, $this->request->int('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $total = (int)Db::value(
            "SELECT COUNT(*) FROM publications p JOIN messages m ON m.id = p.message_id WHERE {$where}",
            $params
        );

        return $this->view('logs', [
            'title'        => 'Журнал',
            'rows'         => Db::all(
                "SELECT p.*, m.source_message_id, m.media_kind, s.name AS source_name, s.tg_identifier AS source_peer,
                        d.name AS destination_name
                 FROM publications p
                 JOIN messages m ON m.id = p.message_id
                 JOIN sources s ON s.id = m.source_id
                 JOIN destinations d ON d.id = p.destination_id
                 WHERE {$where}
                 ORDER BY p.id DESC
                 LIMIT " . self::PER_PAGE . " OFFSET " . $offset,
                $params
            ),
            'sources'      => Db::all('SELECT id, name FROM sources ORDER BY name'),
            'destinations' => Db::all('SELECT id, name FROM destinations ORDER BY name'),
            'filters'      => ['status' => $status, 'source_id' => $sourceId, 'destination_id' => $destinationId,
                               'from' => $from, 'to' => $to, 'message_id' => $search],
            'page'         => $page,
            'pages'        => max(1, (int)ceil($total / self::PER_PAGE)),
            'total'        => $total,
            'events'       => Db::all("SELECT * FROM logs WHERE level IN ('warning','error') ORDER BY id DESC LIMIT 20"),
        ]);
    }

    public function retry(array $params): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }
        Db::run(
            "UPDATE publications SET status = 'RETRY', scheduled_at = ?, attempts = 0, last_error = NULL
             WHERE id = ? AND status IN ('ERROR','SKIPPED')",
            [Db::now(), (int)$params['id']]
        );
        return $this->back('/logs', 'Публикация возвращена в очередь');
    }
}
