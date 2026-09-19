<?php

declare(strict_types=1);

use App\Controllers\AccountsController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DestinationsController;
use App\Controllers\RoutesController;
use App\Controllers\SourcesController;
use App\Core\Request;
use App\Core\Router;

require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::fromGlobals();
$router = new Router();

$auth = new AuthController($request);
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);

$router->get('/', [new DashboardController($request), 'index']);

$accounts = new AccountsController($request);
$router->get('/accounts', [$accounts, 'index']);
$router->post('/accounts', [$accounts, 'store']);
$router->post('/accounts/{id}/code', [$accounts, 'code']);
$router->post('/accounts/{id}/password', [$accounts, 'password']);
$router->post('/accounts/{id}/logout', [$accounts, 'logout']);
$router->post('/accounts/{id}/delete', [$accounts, 'delete']);

$sources = new SourcesController($request);
$router->get('/sources', [$sources, 'index']);
$router->post('/sources/save', [$sources, 'save']);
$router->post('/sources/{id}/test', [$sources, 'test']);
$router->post('/sources/{id}/toggle', [$sources, 'toggle']);
$router->post('/sources/{id}/delete', [$sources, 'delete']);

$destinations = new DestinationsController($request);
$router->get('/destinations', [$destinations, 'index']);
$router->post('/destinations/save', [$destinations, 'save']);
$router->post('/destinations/{id}/test', [$destinations, 'test']);
$router->post('/destinations/{id}/toggle', [$destinations, 'toggle']);
$router->post('/destinations/{id}/delete', [$destinations, 'delete']);

$routes = new RoutesController($request);
$router->get('/routes', [$routes, 'index']);
$router->post('/routes/save', [$routes, 'save']);
$router->post('/routes/{id}/toggle', [$routes, 'toggle']);
$router->post('/routes/{id}/delete', [$routes, 'delete']);

$router->dispatch($request)->send();
