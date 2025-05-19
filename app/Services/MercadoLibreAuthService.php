<?php

namespace App\Services;

use App\Models\MercadoLibreToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoLibreAuthService
{
    public function getAccessToken()
    {
        $token = MercadoLibreToken::latest()->first();

        // Si no hay token o ya expiró
        if (!$token || now()->gte($token->expires_at)) {
            Log::info("Token expirado o no existente. Renovando...");

            $response = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => env('MERCADO_LIBRE_APP_ID'),
                'client_secret' => env('MERCADO_LIBRE_CLIENT_SECRET'),
                'refresh_token' => $token?->refresh_token,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                $newToken = MercadoLibreToken::create([
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                    'expires_at' => now()->addSeconds($data['expires_in']),
                ]);

                Log::info("Nuevo access_token obtenido y guardado.");
                return $newToken->access_token;
            } else {
                Log::error("Error al renovar token: " . $response->body());
                throw new \Exception("Error al renovar el token: " . $response->body());
            }
        }

        return $token->access_token;
    }
}