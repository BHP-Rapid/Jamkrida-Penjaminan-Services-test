<?php

namespace Tests\Feature;

use App\Http\Middleware\HorizonSessionAuthMiddleware;
use Tests\TestCase;

class HorizonSessionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:D5UqmVP9NabskJ3akhdX/UsCm29b2mgKrjUtS87tQi4=');
        config()->set('horizon.auth.user', 'admin');
        config()->set('horizon.auth.password', 'secret');
    }

    public function test_horizon_redirects_guest_to_login_page(): void
    {
        $response = $this->get('/horizon');

        $response->assertRedirect(HorizonSessionAuthMiddleware::horizonUrl('login'));
    }

    public function test_horizon_login_page_is_rendered(): void
    {
        $response = $this->get('/horizon/login');

        $response
            ->assertOk()
            ->assertSee('Horizon')
            ->assertSee('Masuk');
    }

    public function test_horizon_login_stores_session(): void
    {
        $response = $this->post('/horizon/login', [
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $response->assertRedirect(HorizonSessionAuthMiddleware::horizonUrl());
        $this->assertTrue(session()->get(HorizonSessionAuthMiddleware::SESSION_KEY));
    }

    public function test_horizon_login_rejects_invalid_credentials(): void
    {
        $response = $this->from('/horizon/login')->post('/horizon/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertRedirect('/horizon/login')
            ->assertSessionHasErrors('username');
    }

    public function test_horizon_logout_clears_session(): void
    {
        $response = $this
            ->withSession([HorizonSessionAuthMiddleware::SESSION_KEY => true])
            ->post('/horizon/logout');

        $response->assertRedirect(HorizonSessionAuthMiddleware::horizonUrl('login'));
        $this->assertFalse((bool) session()->get(HorizonSessionAuthMiddleware::SESSION_KEY));
    }

    public function test_horizon_url_uses_app_url_base(): void
    {
        // url() uses APP_URL, so if APP_URL includes a sub-path the
        // generated Horizon URLs will automatically include it.
        $base = 'https://example.com/penjaminan-test';
        config()->set('app.url', $base);
        \Illuminate\Support\Facades\URL::forceRootUrl($base);

        $this->assertStringEndsWith(
            '/penjaminan-test/horizon/login',
            HorizonSessionAuthMiddleware::horizonUrl('login')
        );

        $this->assertStringEndsWith(
            '/penjaminan-test/horizon',
            HorizonSessionAuthMiddleware::horizonUrl()
        );
    }
}
