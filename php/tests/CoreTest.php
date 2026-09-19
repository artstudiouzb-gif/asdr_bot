<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    public function testSignatureRoundTrip(): void
    {
        $signature = Crypto::sign('данные');
        self::assertTrue(Crypto::verify('данные', $signature));
        self::assertFalse(Crypto::verify('другие данные', $signature));
    }

    public function testEncryptionRoundTrip(): void
    {
        $token = '123456:AAHf-secret-bot-token';
        $encrypted = Crypto::encrypt($token);
        self::assertNotSame($token, $encrypted);
        self::assertSame($token, Crypto::decrypt($encrypted));
    }

    public function testDecryptRejectsTamperedValue(): void
    {
        $encrypted = Crypto::encrypt('секрет');
        $broken = substr($encrypted, 0, -4) . 'AAAA';
        self::assertNull(Crypto::decrypt($broken));
    }

    public function testCsrfTokenIsBoundToSession(): void
    {
        $token = Csrf::token('session-a');
        self::assertTrue(Csrf::check($token, 'session-a'));
        self::assertFalse(Csrf::check($token, 'session-b'));
        self::assertFalse(Csrf::check('', 'session-a'));
    }

    public function testCsrfAcceptsNonAsciiWithoutError(): void
    {
        self::assertFalse(Csrf::check('подделка', 'session-a'));
    }

    public function testRouterMatchesParameters(): void
    {
        $router = new Router();
        $router->get('/sources/{id}/edit', static fn($request, array $params): Response
            => Response::text('id=' . $params['id']));

        $response = $router->dispatch($this->request('GET', '/sources/42/edit'));
        self::assertSame(200, $response->status());
        self::assertSame('id=42', $response->body());
    }

    public function testRouterReturns405ForWrongMethod(): void
    {
        $router = new Router();
        $router->post('/login', static fn(): Response => Response::text('ok'));
        self::assertSame(405, $router->dispatch($this->request('GET', '/login'))->status());
    }

    public function testRouterReturns404ForUnknownPath(): void
    {
        $router = new Router();
        $router->get('/', static fn(): Response => Response::text('ok'));
        self::assertSame(404, $router->dispatch($this->request('GET', '/nope'))->status());
    }

    public function testRequestTrimsTrailingSlash(): void
    {
        self::assertSame('/sources', $this->request('GET', '/sources/')->path);
        self::assertSame('/', $this->request('GET', '/')->path);
    }

    public function testRequestHelpers(): void
    {
        $request = new Request('POST', '/x', ['page' => '2'], ['name' => '  Канал  ', 'active' => '1'], [], []);
        self::assertSame('Канал', $request->input('name'));
        self::assertSame(2, $request->int('page'));
        self::assertTrue($request->bool('active'));
        self::assertSame('по умолчанию', $request->input('missing', 'по умолчанию'));
    }

    public function testResponseRedirectCarriesLocation(): void
    {
        $response = Response::redirect('/login');
        self::assertSame(303, $response->status());
    }

    private function request(string $method, string $path): Request
    {
        $normalized = $path === '/' ? '/' : rtrim($path, '/');
        return new Request($method, $normalized, [], [], [], []);
    }
}
