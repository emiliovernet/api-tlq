<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FlokzuService
{
    public function enviarOrden(array $order): void
    {
        try {
            $item = $order['order_items'][0]['item'] ?? [];
            $shipping = $order['shipping'] ?? [];
            $payment = $order['payments'][0] ?? [];
            $buyer = $order['buyer'] ?? [];
            $direccion = $shipping['receiver_address'] ?? [];

            $fechaVenta = isset($order['date_created']) ? Carbon::parse($order['date_created'])->format('Y/m/d') : null;
            $fechaEntrega = isset($shipping['estimated_delivery_final']) ? Carbon::parse($shipping['estimated_delivery_final'])->format('Y/m/d') : null;

            $payload = [
                "processId" => env('FLOKZU_PROCESS_ID'),
                "data" => [
                    "TIPOVENTA" => "ML",
                    "NROVENTA" => (string) $order['id'],
                    "FECHAVENTA" => $fechaVenta,
                    "FECHAENTREGA" => $fechaEntrega,
                    "LINKML" => "https://www.mercadolibre.com.ar/ventas/{$order['id']}/detalle",
                    "LINKAMAZON" => "https://www.amazon.com/dp/B0C2DTFT3K/?th=1",
                    "Cantidaddeunidades" => (string) ($order['order_items'][0]['quantity'] ?? 1),
                    "PRECIOVENTA" => (string) ($order['order_items'][0]['unit_price'] ?? 0),
                    "SALDOML" => (string) ($payment['total_paid_amount'] ?? 0),
                    "COMISIONML" => (string) ($order['order_items'][0]['sale_fee'] ?? 0),
                    "COSTOENVIO" => (string) ($payment['shipping_cost'] ?? 0),
                    "Impuestos" => (string) ($payment['taxes_amount'] ?? 0),
                    "CUITCOMPRADOR" => $buyer['billing_info']['doc_number'] ?? '',
                    "NOMBREDESTINATARIO" => trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')),
                    "Datoscliente" => trim(optional($direccion)['street_name'] . ' ' . optional($direccion)['street_number']),
                    "CIUDAD" => optional($direccion)['city']['name'] ?? '',
                    "PROVINCIA" => optional($direccion)['state']['name'] ?? '',
                    "CODIGOPOSTAL" => optional($direccion)['zip_code'] ?? '',
                ]
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Api-Key' => env('FLOKZU_API_KEY'),
                'X-Username' => env('FLOKZU_USERNAME'),
            ])->post('https://app.flokzu.com/flokzuopenapi/api/v2/process/instance', $payload);

            if ($response->successful()) {
                Log::info("Orden {$order['id']} enviada a Flokzu exitosamente.");
                Log::info("Respuesta Flokzu: " . $response->body());
            } else {
                Log::error("Fallo al enviar orden {$order['id']} a Flokzu.");
                Log::error("Status: {$response->status()} - Body: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Error en FlokzuService para orden {$order['id']}: " . $e->getMessage());
        }
    }
}