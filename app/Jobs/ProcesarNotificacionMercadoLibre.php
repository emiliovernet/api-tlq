<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\MercadoLibreAuthService;
use App\Services\FlokzuService;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcesarNotificacionMercadoLibre implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $orderId;
    protected int $userId;
    protected int $applicationId;

    public function __construct(string $orderId, int $userId, int $applicationId)
    {
        $this->orderId = $orderId;
        $this->userId = $userId;
        $this->applicationId = $applicationId;
    }

    public function handle(MercadoLibreAuthService $authService, FlokzuService $flokzuService): void
    {
        try {
            $accessToken = $authService->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
            ])->get("https://api.mercadolibre.com/orders/{$this->orderId}");

            if (!$response->successful()) {
                Log::error("Error al obtener orden {$this->orderId}: " . $response->body());
                return;
            }

            $order = $response->json();

            $item = $order['order_items'][0]['item'] ?? [];
            $shipping = $order['shipping'] ?? [];
            $payment = $order['payments'][0] ?? [];
            $buyer = $order['buyer'] ?? [];
            $direccion = $shipping['receiver_address'] ?? [];

            Order::UpdateorCreate([
                'nro_venta' => $order['id'],
                'tipo_venta' => 'ML',
                'fecha_venta' => $order['date_created'] ?? null,
                'fecha_entrega' => $shipping['estimated_delivery_final'] ?? null,
                'nombre_producto' => $item['title'] ?? null,
                'link_ml' => "https://www.mercadolibre.com.ar/ventas/{$order['id']}/detalle",
                'cantidad_unidades' => $order['order_items'][0]['quantity'] ?? 1,
                'precio_venta' => $order['order_items'][0]['unit_price'] ?? 0,
                'saldo_mercadolibre' => $payment['total_paid_amount'] ?? 0,
                'comision_ml' => $order['order_items'][0]['sale_fee'] ?? 0,
                'costo_envio' => $payment['shipping_cost'] ?? null,
                'impuestos' => $payment['taxes_amount'] ?? 0,
                'cuit_comprador' => $buyer['billing_info']['doc_number'] ?? null,
                'nombre_destinatario' => trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')),
                'direccion_cliente' => trim(optional($direccion)['street_name'] . ' ' . optional($direccion)['street_number']),
                'ciudad' => optional($direccion)['city']['name'] ?? null,
                'provincia' => optional($direccion)['state']['name'] ?? null,
                'codigo_postal' => optional($direccion)['zip_code'] ?? null,
            ]);

            Log::info("Orden {$this->orderId} guardada exitosamente.");

            // Disparar a Flokzu
            $flokzuService->enviarOrden($order);

        } catch (\Exception $e) {
            Log::error("Excepción al procesar orden {$this->orderId}: " . $e->getMessage());
        }
    }
}