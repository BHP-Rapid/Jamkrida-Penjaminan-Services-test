<?php

namespace Tests\Feature;

use App\Http\Middleware\HorizonSessionAuthMiddleware;
use Tests\TestCase;

class HorizonSessionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\URL::forceRootUrl(null);
        \Illuminate\Support\Facades\URL::forceScheme(null);

        config()->set('app.key', 'base64:D5UqmVP9NabskJ3akhdX/UsCm29b2mgKrjUtS87tQi4=');
        config()->set('horizon.auth.user', 'admin');
        config()->set('horizon.auth.password', 'secret');
        config()->set('horizon.proxy_path', '');
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

    public function test_horizon_dashboard_shows_logout_button(): void
    {
        $response = $this
            ->withSession([
                HorizonSessionAuthMiddleware::SESSION_KEY => true,
                'horizon_user' => 'admin',
            ])
            ->get('/horizon');

        $response
            ->assertOk()
            ->assertSee('Keluar')
            ->assertSee('Logout from Horizon')
            ->assertSee(HorizonSessionAuthMiddleware::horizonUrl('logout'), false);
    }

    public function test_horizon_url_uses_app_url_base(): void
    {
        $base = 'https://example.com/penjaminan-test';
        config()->set('app.url', $base);
        \Illuminate\Support\Facades\URL::forceRootUrl($base);
        \Illuminate\Support\Facades\URL::forceScheme('https');

        $this->assertStringEndsWith(
            '/penjaminan-test/horizon/login',
            HorizonSessionAuthMiddleware::horizonUrl('login')
        );

        $this->assertStringEndsWith(
            '/penjaminan-test/horizon',
            HorizonSessionAuthMiddleware::horizonUrl()
        );
    }

    public function test_horizon_url_uses_proxy_path_when_app_url_has_no_prefix(): void
    {
        $base = 'https://example.com';
        config()->set('app.url', $base);
        config()->set('horizon.proxy_path', '/penjaminan-test');
        \Illuminate\Support\Facades\URL::forceRootUrl($base);
        \Illuminate\Support\Facades\URL::forceScheme('https');

        $this->assertSame(
            'https://example.com/penjaminan-test/horizon/logout',
            HorizonSessionAuthMiddleware::horizonUrl('logout')
        );
    }

    public function test_horizon_url_does_not_duplicate_proxy_path(): void
    {
        $base = 'https://example.com/penjaminan-test';
        config()->set('app.url', $base);
        config()->set('horizon.proxy_path', '/penjaminan-test');
        \Illuminate\Support\Facades\URL::forceRootUrl($base);
        \Illuminate\Support\Facades\URL::forceScheme('https');

        $this->assertSame(
            'https://example.com/penjaminan-test/horizon/login',
            HorizonSessionAuthMiddleware::horizonUrl('login')
        );
    }
}
