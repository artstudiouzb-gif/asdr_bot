<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;

final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/');
        }
        return Response::html(View::render('auth/login', [
            'csrf'  => Csrf::field($this->auth->sessionToken()),
            'error' => $this->request->input('e'),
        ], null));
    }

    public function login(): Response
    {
        if (!Csrf::check($this->request->raw('csrf'), $this->auth->sessionToken())) {
            return Response::redirect('/login?e=' . rawurlencode('Форма устарела, попробуйте ещё раз'));
        }
        [$ok, $message, $token] = $this->auth->attempt(
            $this->request->input('username'),
            $this->request->raw('password')
        );
        if (!$ok) {
            return Response::redirect('/login?e=' . rawurlencode($message));
        }
        return Response::redirect('/', [$this->auth->cookie((string)$token)]);
    }

    public function logout(): Response
    {
        if (!Csrf::check($this->request->raw('csrf'), $this->auth->sessionToken())) {
            return Response::redirect('/');
        }
        $this->auth->logout();
        return Response::redirect('/login', [$this->auth->cookie('')]);
    }
}
