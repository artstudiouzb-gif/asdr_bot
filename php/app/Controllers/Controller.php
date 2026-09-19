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
