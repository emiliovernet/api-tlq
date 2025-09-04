<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlokzuService
{
    public function enviarOrden(Order $order): ?string
    {
        try {
            $data = [
                "TIPOVENTA" => $order->tipo_venta,
                "NROVENTA" => $order->nro_venta,
                "FECHAVENTA" => optional($order->fecha_venta)->format('Y/m/d'),
                "FECHAENTREGA" => optional($order->fecha_entrega)->format('Y/m/d'),
                "NOMBREPRODUCTO" => $order->nombre_producto ?? '', // <-- ENVIAR NOMBRE DEL PRODUCTO
                "SKU" => $order->sku ?? '', // <-- ENVIAR SKU
                "LINKML" => $order->link_ml ?? '',
                "LINKAMAZON" => $order->link_amazon ?? '',
                "Cantidad de Unidades" => (string) $order->cantidad_unidades,
                "PRECIOVENTA" => (string) $order->precio_venta,
                "SALDOML" => (string) ($order->saldo_mercadolibre ?? ''),
                "COMISIONML" => $order->comision_ml ?? '',
                "COSTOENVIO" => (is_null($order->costo_envio) || $order->costo_envio === 0) ? '' : (string) $order->costo_envio,
                "Impuestos" => (string) ($order->impuestos ?? ''),
                "CUITCOMPRADOR" => $order->cuit_comprador ?? '',
                "NOMBREDESTINATARIO" => $order->nombre_destinatario ?? '',
                "Datos Cliente" => $order->direccion_cliente ?? '',
                "CIUDAD" => $order->ciudad ?? '',
                "PROVINCIA" => $order->provincia ?? '',
                "CODIGO POSTAL" => $order->codigo_postal ?? '',
                "APORTE ML" => (string) ($order->aporte_ml ?? '')
            ];

            $data = array_map(fn($v) => is_null($v) ? '' : $v, $data);

            $payload = [
                "processId" => env('FLOKZU_PROCESS_ID'),
                "data" => $data
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Api-Key' => env('FLOKZU_API_KEY'),
                'X-Username' => env('FLOKZU_USERNAME'),
            ])->post('https://app.flokzu.com/flokzuopenapi/api/v2/process/instance', $payload);

            if ($response->successful()) {
                $body = $response->json();
                $identifier = $body['identifier'] ?? null; // <-- Acceso seguro
                Log::info("Orden {$order->nro_venta} enviada a Flokzu exitosamente. Identifier: {$identifier}");
                $this->enviarNotaMercadoLibre($order->nro_venta, $identifier);
                return $identifier;
            } else {
                Log::error("Fallo al enviar orden {$order->nro_venta} a Flokzu.");
                Log::error("Status: {$response->status()} - Body: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Error en FlokzuService para orden {$order->nro_venta}: " . $e->getMessage());
        }
        return null;
    }

    private function enviarNotaMercadoLibre(string $orderId, string $identifier): void
    {
        try {
            $accessToken = app(MercadoLibreAuthService::class)->getAccessToken();

            $noteResponse = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("https://api.mercadolibre.com/orders/{$orderId}/notes", [
                    'note' => $identifier
                ]);

            if ($noteResponse->successful()) {
                Log::info("Nota agregada a la orden {$orderId} con identifier {$identifier}");
            } else {
                Log::warning("No se pudo agregar la nota a la orden {$orderId}. Respuesta: " . $noteResponse->body());
            }
        } catch (\Exception $e) {
            Log::error("Error al enviar nota a MercadoLibre para orden {$orderId}: " . $e->getMessage());
        }
    }

    public function actualizarOrdenCancelada(Order $order): bool
    {
        try {
            // Verificar que la orden tenga un identifier de Flokzu
            if (empty($order->flokzu_identifier)) {
                Log::warning("Orden {$order->nro_venta} no tiene identifier de Flokzu, no se puede actualizar.");
                return false;
            }

            $payload = [
                "identifier" => $order->flokzu_identifier,
                "data" => [
                    "ESTADO" => "CANCELADA"
                ]
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Api-Key' => env('FLOKZU_API_KEY'),
                'X-Username' => env('FLOKZU_USERNAME'),
            ])->put('https://app.flokzu.com/flokzuopenapi/api/v2/process/instance', $payload);

            if ($response->successful()) {
                Log::info("Orden {$order->nro_venta} actualizada a CANCELADA en Flokzu. Identifier: {$order->flokzu_identifier}");
                return true;
            } else {
                Log::error("Fallo al actualizar orden {$order->nro_venta} a CANCELADA en Flokzu.");
                Log::error("Status: {$response->status()} - Body: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error al actualizar orden cancelada {$order->nro_venta} en Flokzu: " . $e->getMessage());
            return false;
        }
    }
}
