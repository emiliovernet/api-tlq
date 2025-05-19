<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\MercadoLibreToken;
use App\Services\MercadoLibreAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MercadoLibreAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_access_token_when_token_is_valid()
    {
        // Creamos un token válido
        $token = MercadoLibreToken::create([
            'access_token' => 'valid_token',
            'refresh_token' => 'refresh_token_123',
            'expires_at' => now()->addMinutes(10),
        ]);

        $authService = new MercadoLibreAuthService();
        $accessToken = $authService->getAccessToken();

        $this->assertEquals('valid_token', $accessToken);
    }

    public function test_get_access_token_refreshes_when_expired()
    {
        Http::fake([
            'https://api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'new_token_456',
                'refresh_token' => 'new_refresh_token_456',
                'expires_in' => 3600,
            ], 200),
        ]);

        // Creamos un token vencido
        $token = MercadoLibreToken::create([
            'access_token' => 'expired_token',
            'refresh_token' => 'refresh_token_123',
            'expires_at' => now()->subMinutes(5),
        ]);

        $authService = new MercadoLibreAuthService();
        $accessToken = $authService->getAccessToken();

        $this->assertEquals('new_token_456', $accessToken);

        $this->assertDatabaseHas('mercado_libre_tokens', [
            'access_token' => 'new_token_456',
            'refresh_token' => 'new_refresh_token_456',
        ]);
    }
}