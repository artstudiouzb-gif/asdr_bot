<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::fromGlobals();
$router = new Router();

$auth = new AuthController($request);
$dashboard = new DashboardController($request);

$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/', [$dashboard, 'index']);

$router->dispatch($request)->send();
