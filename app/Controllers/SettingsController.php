<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Url;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        $scheme = $this->request->isHttps() ? 'https' : 'http';
        $host = (string)($this->request->server['HTTP_HOST'] ?? '');
        $cronKey = (string)(Env::get('CRON_KEY', '') ?? '');

        return $this->view('settings', [
            'title'    => 'Настройки',
            'settings' => Settings::all(),
            'cronLine' => '/usr/bin/php ' . APP_ROOT . '/bin/cron.php',
            'cronUrl'  => $cronKey === '' ? null : $scheme . '://' . $host . Url::to('/cron.php') . '?key=' . $cronKey,
            'timezone' => Env::get('TIMEZONE', 'Asia/Tashkent'),
            'apiReady' => Env::get('TG_API_ID') !== null && Env::get('TG_API_HASH') !== null,
            'lastRun'  => Db::one('SELECT * FROM cron_runs ORDER BY id DESC LIMIT 1'),
            'sessions' => Db::all(
                'SELECT id, ip, user_agent, created_at, last_seen_at FROM admin_sessions
                 WHERE admin_id = ? AND expires_at > ? ORDER BY last_seen_at DESC',
                [(int)($this->auth->user()['id'] ?? 0), Db::now()]
            ),
            'currentSession' => hash('sha256', $this->auth->sessionToken()),
        ]);
    }

    public function save(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        Settings::set('retry_max', (string)max(1, min($this->request->int('retry_max', 4), 10)));
        Settings::set('backfill_on_first_run', (string)max(0, min($this->request->int('backfill_on_first_run'), 50)));
        Settings::set('publish_batch', (string)max(1, min($this->request->int('publish_batch', 20), 100)));
        return $this->back('/settings', 'Настройки сохранены');
    }

    public function password(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $user = $this->auth->user();
        $current = $this->request->raw('current');
        $new = $this->request->raw('new');

        if ($user === null || !password_verify($current, (string)$user['password_hash'])) {
            return $this->back('/settings', '', 'Текущий пароль указан неверно');
        }
        if (strlen($new) < 10) {
            return $this->back('/settings', '', 'Новый пароль — не короче 10 символов');
        }
        if ($new !== $this->request->raw('confirm')) {
            return $this->back('/settings', '', 'Пароли не совпадают');
        }
        Db::update('admins', ['password_hash' => Auth::hash($new)], 'id = :id', ['id' => (int)$user['id']]);
        // остальные сеансы выходят: если пароль меняли из-за утечки, чужой сеанс не должен выжить
        Db::delete('admin_sessions', 'admin_id = ? AND id <> ?',
            [(int)$user['id'], hash('sha256', $this->auth->sessionToken())]);
        return $this->back('/settings', 'Пароль изменён, остальные сеансы завершены');
    }

    public function endSessions(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $user = $this->auth->user();
        Db::delete('admin_sessions', 'admin_id = ? AND id <> ?',
            [(int)($user['id'] ?? 0), hash('sha256', $this->auth->sessionToken())]);
        return $this->back('/settings', 'Все остальные сеансы завершены');
    }
}
