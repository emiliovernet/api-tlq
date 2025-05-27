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

    protected array $requestData;

    public function __construct(array $requestData)
    {
        $this->requestData = $requestData;
    }

    public function handle(MercadoLibreAuthService $authService, FlokzuService $flokzuService): void
    {
        try {
            // Loguea la notificación recibida
            Log::info('Notificación recibida de MercadoLibre:', $this->requestData);

            // Procesar solo si es una notificación de orden
            if ($this->requestData['topic'] !== 'orders_v2') {
                Log::info("Notificación ignorada, topic no es orders_v2: {$this->requestData['topic']}");
                return;
            }

            // Extraer parámetros
            $orderId = str_replace('/orders/', '', $this->requestData['resource']);
            $userId = $this->requestData['user_id'];
            $applicationId = $this->requestData['application_id'];

            $accessToken = $authService->getAccessToken();

            // 1. Obtener orden
            $orderResponse = Http::withToken($accessToken)
                ->get("https://api.mercadolibre.com/orders/{$orderId}");

            if (!$orderResponse->successful()) {
                Log::error("Error al obtener orden {$orderId}: " . $orderResponse->body());
                return;
            }

            $orderData = $orderResponse->json();

            // 2. Obtener billing_info
            $billingResponse = Http::withToken($accessToken)
                ->withHeaders(['x-version' => '2'])
                ->get("https://api.mercadolibre.com/orders/{$orderId}/billing_info");

            if (!$billingResponse->successful()) {
                Log::error("Error al obtener billing_info de orden {$orderId}: " . $billingResponse->body());
                return;
            }

            $billingData = $billingResponse->json();

            // 3. Obtener fecha estimada de entrega
            $shipmentId = $orderData['shipping']['id'] ?? null;
            $fechaEntrega = null;

            if ($shipmentId) {
                $shippingResponse = Http::withToken($accessToken)
                    ->get("https://api.mercadolibre.com/shipments/{$shipmentId}/lead_time");

                if ($shippingResponse->successful()) {
                    $shippingData = $shippingResponse->json();
                    $fechaEntrega = $shippingData['estimated_delivery_final']['date'] ?? null;
                } else {
                    Log::warning("No se pudo obtener lead_time para orden {$orderId}: " . $shippingResponse->body());
                }
            }

            // 4. Crear o actualizar orden
            $order = Order::updateOrCreate(
                ['nro_venta' => $orderId],
                [
                    'tipo_venta' => 'ML',
                    'fecha_venta' => $orderData['date_closed'] ?? null,
                    'fecha_entrega' => $fechaEntrega,
                    'nombre_producto' => null,
                    'link_ml' => "https://www.mercadolibre.com.ar/ventas/{$orderId}/detalle",
                    'link_amazon' => "https://www.amazon.com/dp/{$orderData['order_items'][0]['item']['seller_sku']}",
                    'cantidad_unidades' => $orderData['order_items'][0]['quantity'] ?? 1,
                    'precio_venta' => $orderData['total_amount'] ?? null,
                    'saldo_mercadolibre' => null,
                    'comision_ml' => $orderData['order_items'][0]['sale_fee'] ?? null,
                    'costo_envio' => !empty($orderData['shipping_cost']) && $orderData['shipping_cost'] != 0.00
                        ? $orderData['shipping_cost']
                        : null,
                    'impuestos' => null,
                    'cuit_comprador' => ($billingData['buyer']['billing_info']['identification']['type'] ?? '') === 'CUIT'
                        ? $billingData['buyer']['billing_info']['identification']['number']
                        : null,
                    'nombre_destinatario' => trim(($billingData['buyer']['billing_info']['name'] ?? '') . ' ' . ($billingData['buyer']['billing_info']['last_name'] ?? '')),
                    'direccion_cliente' => trim(($billingData['buyer']['billing_info']['address']['street_name'] ?? '') . ' ' . ($billingData['buyer']['billing_info']['address']['street_number'] ?? '')),
                    'ciudad' => $billingData['buyer']['billing_info']['address']['city_name'] ?? '',
                    'provincia' => $billingData['buyer']['billing_info']['address']['state']['name'] ?? '',
                    'codigo_postal' => $billingData['buyer']['billing_info']['address']['zip_code'] ?? '',
                ]
            );

            Log::info("Orden {$orderId} guardada exitosamente.");

            // Disparar a Flokzu
            $flokzuService->enviarOrden($order);

        } catch (\Exception $e) {
            Log::error("Excepción al procesar orden {$orderId}: " . $e->getMessage());
        }
    }
}