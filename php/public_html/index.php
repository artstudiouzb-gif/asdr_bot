<?php

declare(strict_types=1);

use App\Controllers\AccountsController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DestinationsController;
use App\Controllers\FiltersController;
use App\Controllers\LogsController;
use App\Controllers\PreviewController;
use App\Controllers\RulesController;
use App\Controllers\RoutesController;
use App\Controllers\SettingsController;
use App\Controllers\SignaturesController;
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

$preview = new PreviewController($request);
$router->get('/preview', [$preview, 'index']);
$router->post('/preview', [$preview, 'process']);

$logs = new LogsController($request);
$router->get('/logs', [$logs, 'index']);
$router->post('/logs/{id}/retry', [$logs, 'retry']);

$rules = new RulesController($request);
$router->get('/rules', [$rules, 'index']);
$router->post('/rules/save', [$rules, 'save']);
$router->post('/rules/{id}/toggle', [$rules, 'toggle']);
$router->post('/rules/{id}/delete', [$rules, 'delete']);
$router->post('/rule-sets/save', [$rules, 'saveSet']);
$router->post('/rule-sets/{id}/default', [$rules, 'makeDefault']);
$router->post('/rule-sets/{id}/delete', [$rules, 'deleteSet']);

$signatures = new SignaturesController($request);
$router->get('/signatures', [$signatures, 'index']);
$router->post('/signatures/save', [$signatures, 'save']);
$router->post('/signatures/{id}/toggle', [$signatures, 'toggle']);
$router->post('/signatures/{id}/delete', [$signatures, 'delete']);

$filters = new FiltersController($request);
$router->get('/filters', [$filters, 'index']);
$router->post('/filters/save', [$filters, 'save']);
$router->post('/filters/{id}/toggle', [$filters, 'toggle']);
$router->post('/filters/{id}/delete', [$filters, 'delete']);
$router->post('/filter-sets/save', [$filters, 'saveSet']);
$router->post('/filter-sets/{id}/delete', [$filters, 'deleteSet']);

$settings = new SettingsController($request);
$router->get('/settings', [$settings, 'index']);
$router->post('/settings/save', [$settings, 'save']);
$router->post('/settings/password', [$settings, 'password']);
$router->post('/settings/sessions', [$settings, 'endSessions']);

$router->dispatch($request)->send();
