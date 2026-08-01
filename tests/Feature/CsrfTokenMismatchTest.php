<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class CsrfTokenMismatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_token_endpoint_returns_a_token(): void
    {
        $this->get('/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_expired_token_returns_to_the_previous_page_with_a_message(): void
    {
        $request = Request::create('http://localhost/login', 'POST', ['email' => 'someone@example.com']);
        $request->headers->set('referer', 'http://localhost/login');
        $request->setLaravelSession($this->app['session.store']);

        $response = $this->app->make(ExceptionHandler::class)
            ->render($request, new TokenMismatchException());

        $this->assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('http://localhost/login?error=', $location);
        $this->assertStringContainsString(urlencode('セッションの有効期限が切れました。'), $location);
    }

    public function test_expired_token_on_a_json_request_returns_419_with_a_message(): void
    {
        $request = Request::create('http://localhost/photos/bulk/delete', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->setLaravelSession($this->app['session.store']);

        $response = $this->app->make(ExceptionHandler::class)
            ->render($request, new TokenMismatchException());

        $this->assertSame(419, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('セッションの有効期限', $payload['message'] ?? '');
    }
}
