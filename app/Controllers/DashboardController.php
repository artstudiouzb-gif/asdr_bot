<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $stats = [
            'sources'       => (int)Db::value('SELECT COUNT(*) FROM sources'),
            'sourcesActive' => (int)Db::value('SELECT COUNT(*) FROM sources WHERE is_active = 1'),
            'routes'        => (int)Db::value('SELECT COUNT(*) FROM routes WHERE is_active = 1'),
            'messages'      => (int)Db::value('SELECT COUNT(*) FROM messages'),
            'published'     => (int)Db::value('SELECT COUNT(*) FROM publications WHERE status = ? AND published_at > ?', ['PUBLISHED', $since]),
            'skipped'       => (int)Db::value('SELECT COUNT(*) FROM publications WHERE status = ? AND processed_at > ?', ['SKIPPED', $since]),
            'errors'        => (int)Db::value('SELECT COUNT(*) FROM publications WHERE status = ? ', ['ERROR']),
            'queue'         => (int)Db::value('SELECT COUNT(*) FROM publications WHERE status IN (?, ?)', ['NEW', 'RETRY']),
        ];

        $lastRun = Db::one('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 1');
        $cronAgeMinutes = $lastRun === null ? null : (int)floor((time() - strtotime((string)$lastRun['started_at'])) / 60);

        return $this->view('dashboard', [
            'stats'          => $stats,
            'lastRun'        => $lastRun,
            'cronAgeMinutes' => $cronAgeMinutes,
            'recent'         => Db::all(
                'SELECT p.*, d.name AS destination_name, s.name AS source_name, m.source_message_id
                 FROM publications p
                 JOIN destinations d ON d.id = p.destination_id
                 JOIN messages m ON m.id = p.message_id
                 JOIN sources s ON s.id = m.source_id
                 ORDER BY p.id DESC LIMIT 10'
            ),
            'problems'       => Db::all(
                "SELECT * FROM logs WHERE level IN ('warning','error') ORDER BY id DESC LIMIT 10"
            ),
        ]);
    }
}
