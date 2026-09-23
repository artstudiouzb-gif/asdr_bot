<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

abstract class Controller
{
    protected Auth $auth;

    public function __construct(protected readonly Request $request)
    {
        $this->auth = new Auth($request);
    }

    protected function requireAuth(): ?Response
    {
        return $this->auth->check() ? null : Response::redirect('/login');
    }

    protected function requireCsrf(): ?Response
    {
        if (!Csrf::check($this->request->raw('csrf'), $this->auth->sessionToken())) {
            return Response::text('403 — форма устарела, обновите страницу и повторите', 403);
        }
        return null;
    }

    /** Вход + CSRF — для всех изменяющих запросов. */
    protected function guardPost(): ?Response
    {
        return $this->requireAuth() ?? $this->requireCsrf();
    }

    protected function back(string $path, string $message = '', string $error = ''): Response
    {
        $query = [];
        if ($message !== '') {
            $query['m'] = $message;
        }
        if ($error !== '') {
            $query['e'] = $error;
        }
        return Response::redirect($path . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /** Ставит задание воркеру: панель сама к Telegram не обращается. */
    protected function queueCommand(int $accountId, string $command, array $payload = []): void
    {
        \App\Core\Db::insert('tg_commands', [
            'account_id' => $accountId,
            'command'    => $command,
            'payload'    => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status'     => 'queued',
            'created_at' => \App\Core\Db::now(),
        ]);
    }

    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        $user = $this->auth->user();
        $data += [
            'user'       => $user,
            'csrf'       => Csrf::field($this->auth->sessionToken()),
            'activePath' => $this->request->path,
            'flash'      => $this->request->input('m'),
            'flashError' => $this->request->input('e'),
        ];
        return Response::html(View::render($template, $data), $status);
    }
}
