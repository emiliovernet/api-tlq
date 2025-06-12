<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Producto;
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

            // Validar el tipo de notificación
            $topic = $this->requestData['topic'] ?? null;

            if ($topic === 'orders_v2') {
                $this->handleOrderNotification($authService, $flokzuService);
            } elseif ($topic === 'items') {
                $this->handleItemNotification($authService);
            } else {
                Log::info("Notificación ignorada, topic no soportado: {$topic}");
                return;
            }
        } catch (\Exception $e) {
            Log::error("Excepción al procesar notificación: " . $e->getMessage());
        }
    }

    protected function handleOrderNotification(MercadoLibreAuthService $authService, FlokzuService $flokzuService): void
    {
        try {
            // Extraer parámetros
            $orderId = str_replace('/orders/', '', $this->requestData['resource']);
            $accessToken = $authService->getAccessToken();

            // Verificar si la orden ya existe
            $existingOrder = Order::where('nro_venta', $orderId)->first();
            if ($existingOrder) {
                Log::info("Orden {$orderId} ya existe, ignorando notificación.");
                return;
            }

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

            // 3. Obtener fecha de entrega desde manufacturing_ending_date
            $fechaEntrega = $orderData['manufacturing_ending_date'] ?? null;

            // 4. Obtener costo de envío desde shipments/lead_time
            $costoEnvio = null;
            $shipmentId = $orderData['shipping']['id'] ?? null;
            if ($shipmentId) {
                $shippingResponse = Http::withToken($accessToken)
                    ->get("https://api.mercadolibre.com/shipments/{$shipmentId}/lead_time");

                if ($shippingResponse->successful()) {
                    $shippingData = $shippingResponse->json();
                    $costoEnvio = $shippingData['list_cost'] ?? null;
                } else {
                    Log::warning("No se pudo obtener lead_time para orden {$orderId}: " . $shippingResponse->body());
                }
            }

            // 5. Construir link_amazon
            $sellerSku = $orderData['order_items'][0]['item']['seller_sku'] ?? null;
            $linkAmazon = $sellerSku ? "https://www.amazon.com/dp/{$sellerSku}" : null;

            // 6. Usar pack_id para el link_ml
            $packId = $orderData['pack_id'] ?? null;
            $linkMl = $packId ? "https://www.mercadolibre.com.ar/ventas/{$packId}/detalle" : null;

            // 7. Crear orden
            $order = Order::create([
                'nro_venta' => $orderId,
                'tipo_venta' => 'ML',
                'fecha_venta' => $orderData['date_closed'] ?? null,
                'fecha_entrega' => $fechaEntrega,
                'nombre_producto' => null,
                'link_ml' => $linkMl,
                'link_amazon' => $linkAmazon,
                'cantidad_unidades' => $orderData['order_items'][0]['quantity'] ?? 1,
                'precio_venta' => $orderData['total_amount'] ?? null,
                'saldo_mercadolibre' => null,
                'comision_ml' => $orderData['order_items'][0]['sale_fee'] ?? null,
                'aporte_ml' => $orderData['payments'][0]['coupon_amount'] ?? null,
                'costo_envio' => $costoEnvio,
                'impuestos' => null,
                'cuit_comprador' => $billingData['buyer']['billing_info']['identification']['number'] ?? null,
                'nombre_destinatario' => trim(($billingData['buyer']['billing_info']['name'] ?? '') . ' ' . ($billingData['buyer']['billing_info']['last_name'] ?? '')),
                'direccion_cliente' => trim(($billingData['buyer']['billing_info']['address']['street_name'] ?? '') . ' ' . ($billingData['buyer']['billing_info']['address']['street_number'] ?? '')),
                'ciudad' => $billingData['buyer']['billing_info']['address']['city_name'] ?? '',
                'provincia' => $billingData['buyer']['billing_info']['address']['state']['name'] ?? '',
                'codigo_postal' => $billingData['buyer']['billing_info']['address']['zip_code'] ?? '',
            ]);

            Log::info("Orden {$orderId} guardada exitosamente.");

            // Disparar a Flokzu
            $flokzuService->enviarOrden($order);

        } catch (\Exception $e) {
            Log::error("Excepción al procesar orden {$orderId}: " . $e->getMessage());
        }
    }

    protected function handleItemNotification(MercadoLibreAuthService $authService): void
    {
        try {
            // Extraer parámetros
            $itemId = str_replace('/items/', '', $this->requestData['resource']);

            // Validar si el producto existe en la base de datos
            $product = Producto::where('id_publicacion', $itemId)->first();

            if (!$product) {
                Log::info("Producto {$itemId} no encontrado en la base de datos.");
                return;
            }

            // Obtener información del producto de MercadoLibre
            $accessToken = $authService->getAccessToken();
            $itemResponse = Http::withToken($accessToken)
                ->get("https://api.mercadolibre.com/items/{$itemId}");

            if (!$itemResponse->successful()) {
                Log::error("Error al obtener producto {$itemId}: " . $itemResponse->body());
                return;
            }

            $itemData = $itemResponse->json();

            // Comparar y actualizar campos si es necesario
            $updates = [];
            if ($product->precio != $itemData['price']) {
                $updates['precio'] = $itemData['price'];
            }
            if ($product->stock != $itemData['available_quantity']) {
                $updates['stock'] = $itemData['available_quantity'];
            }
            if ($product->estado != $itemData['status']) {
                $updates['estado'] = $itemData['status'];
            }

            if (!empty($updates)) {
                $updates['ultima_actualizacion'] = now();
                $product->update($updates);
                Log::info("Producto {$itemData['id']} actualizado:", $updates);
            } else {
                Log::info("Producto {$itemData['id']} sin cambios en los campos monitoreados.");
            }

        } catch (\Exception $e) {
            Log::error("Excepción al procesar producto {$itemId}: " . $e->getMessage());
        }
    }
}