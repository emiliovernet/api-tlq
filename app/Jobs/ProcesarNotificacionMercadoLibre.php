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
            Log::info('Notificación recibida de MercadoLibre:', $this->requestData);

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
            $orderId = str_replace('/orders/', '', $this->requestData['resource']);
            $accessToken = $authService->getAccessToken();

            $existingOrder = Order::where('nro_venta', $orderId)->first();

            $orderResponse = Http::withToken($accessToken)
                ->get("https://api.mercadolibre.com/orders/{$orderId}");

            if (!$orderResponse->successful()) {
                Log::error("Error al obtener orden {$orderId}: " . $orderResponse->body());
                return;
            }

            $orderData = $orderResponse->json();
            $orderStatus = $orderData['status'] ?? '';

            if ($existingOrder) {
                // Si la orden ya existe y sigue en estado paid, ignorar notificación
                if ($existingOrder->estado_orden === 'paid' && $orderStatus === 'paid') {
                    Log::info("Orden {$orderId} ya existe y permanece en estado 'paid', ignorando notificación.");
                    return;
                }

                // Si el estado cambió, actualizar y disparar Google Sheets
                if ($orderStatus !== 'paid') {
                    $existingOrder->update(['estado_orden' => $orderStatus]);
                    $this->cambiarEstadoEnGoogleSheets($existingOrder, $orderStatus);
                    Log::info("Orden {$orderId} actualizada a estado '{$orderStatus}' y enviado a Google Sheets.");
                    return;
                }

                // Si por algún motivo no entra en los casos anteriores, ignorar
                Log::info("Orden {$orderId} ya existe, ignorando notificación.");
                return;
            }

            // Si la orden NO existe y está en estado paid, crear y disparar Flokzu
            if ($orderStatus === 'paid') {
                $billingResponse = Http::withToken($accessToken)
                    ->withHeaders(['x-version' => '2'])
                    ->get("https://api.mercadolibre.com/orders/{$orderId}/billing_info");

                if (!$billingResponse->successful()) {
                    Log::error("Error al obtener billing_info de orden {$orderId}: " . $billingResponse->body());
                    return;
                }

                $billingData = $billingResponse->json();

                $fechaEntrega = $orderData['manufacturing_ending_date'] ?? null;

                $costoEnvio = null;
                $shipmentId = $orderData['shipping']['id'] ?? null;
                if ($shipmentId) {
                    $shippingResponse = Http::withToken($accessToken)
                        ->get("https://api.mercadolibre.com/shipments/{$shipmentId}/lead_time");

                    if ($shippingResponse->successful()) {
                        $shippingData = $shippingResponse->json();
                        $listCost = $shippingData['list_cost'] ?? null;
                        $costoEnvio = ($listCost === 0) ? null : $listCost;
                    } else {
                        Log::warning("No se pudo obtener lead_time para orden {$orderId}: " . $shippingResponse->body());
                    }
                }

                $sellerSku = $orderData['order_items'][0]['item']['seller_sku'] ?? null;
                $nombreProducto = $orderData['order_items'][0]['item']['title'] ?? null; // <-- EXTRAER NOMBRE DEL PRODUCTO
                $linkAmazon = $sellerSku ? "https://www.amazon.com/dp/{$sellerSku}" : null;
                $linkMl = "https://www.mercadolibre.com.ar/ventas/{$orderId}/detalle";

                $order = Order::create([
                    'nro_venta' => $orderId,
                    'tipo_venta' => 'ML',
                    'fecha_venta' => $orderData['date_closed'] ?? null,
                    'fecha_entrega' => $fechaEntrega,
                    'nombre_producto' => $nombreProducto,
                    'sku' => $sellerSku,
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
                    'estado_orden' => $orderData['status'] ?? null,
                ]);

                Log::info("Orden {$orderId} guardada exitosamente.");

                $identifier = $flokzuService->enviarOrden($order);
                if ($identifier) {
                    $order->update(['flokzu_identifier' => $identifier]);
                }
                return;
            }

            // Si la orden NO existe y el estado NO es 'paid', ignorar
            Log::info("Orden {$orderId} ignorada: estado no es 'paid' (estado actual: {$orderStatus})");
            return;

        } catch (\Exception $e) {
            Log::error("Excepción al procesar orden {$orderId}: " . $e->getMessage());
        }
    }

    protected function handleItemNotification(MercadoLibreAuthService $authService): void
    {
        try {
            $itemId = str_replace('/items/', '', $this->requestData['resource']);
            $product = Producto::where('id_publicacion', $itemId)->first();

            if (!$product) {
                Log::info("Producto {$itemId} no encontrado en la base de datos.");
                return;
            }

            $accessToken = $authService->getAccessToken();
            $itemResponse = Http::withToken($accessToken)
                ->get("https://api.mercadolibre.com/items/{$itemId}");

            if (!$itemResponse->successful()) {
                Log::error("Error al obtener producto {$itemId}: " . $itemResponse->body());
                return;
            }

            $itemData = $itemResponse->json();

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

    // Actualiza el método para Google Sheets para enviar el estado
    protected function cambiarEstadoEnGoogleSheets(Order $orden, string $estado): void
    {
        try {
            $webhookUrl = env('GOOGLE_SHEETS_WEBHOOK_URL');

            $data = [
                'identifier' => $orden->flokzu_identifier,
                'estado' => $estado,
            ];

            $response = Http::post($webhookUrl, $data);

            if ($response->successful()) {
                Log::info("Se marcó el proceso Flokzu {$orden->flokzu_identifier} en Sheets con estado '{$estado}'.");
            } else {
                Log::warning("Error al actualizar estado en Sheets: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Excepción al enviar cancelación a Sheets: " . $e->getMessage());
        }
    }

}