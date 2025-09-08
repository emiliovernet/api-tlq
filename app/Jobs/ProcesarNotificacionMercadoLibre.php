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

                // Si el estado cambió, actualizar y disparar Google Sheets + Flokzu si es cancelación
                if ($orderStatus !== $existingOrder->estado_orden) {
                    $existingOrder->update(['estado_orden' => $orderStatus]);
                    $this->cambiarEstadoEnGoogleSheets($existingOrder, $orderStatus);
                    
                    // Si es una cancelación, enviar a Flokzu
                    if ($orderStatus === 'cancelled') {
                        $flokzuService->actualizarOrdenCancelada($existingOrder);
                    }
                    
                    Log::info("Orden {$orderId} actualizada a estado '{$orderStatus}' y enviado a Google Sheets" . 
                             ($orderStatus === 'cancelled' ? ' y Flokzu' : '') . ".");
                    return;
                }

                // Si por algún motivo no entra en los casos anteriores, ignorar
                Log::info("Orden {$orderId} ya existe con el mismo estado '{$orderStatus}', ignorando notificación.");
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

                // Buscar todos los pagos aprobados
                $approvedPayments = collect($orderData['payments'] ?? [])
                    ->where('status', 'approved');

                $saldo_mercadolibre = 0;
                $impuestos = 0;
                $paymentIds = [];

                foreach ($approvedPayments as $payment) {
                    $paymentIds[] = $payment['id'];
                    $paymentResponse = Http::withToken($accessToken)
                        ->get("https://api.mercadopago.com/v1/payments/{$payment['id']}");
                    if ($paymentResponse->successful()) {
                        $paymentData = $paymentResponse->json();
                        $saldo_mercadolibre += floatval($paymentData['transaction_details']['net_received_amount'] ?? 0);
                        $impuestos += collect($paymentData['charges_details'] ?? [])
                            ->where('type', 'tax')
                            ->sum(fn($item) => $item['amounts']['original'] ?? 0);
                    }
                }

                // Si quieres guardar el primer payment_id, puedes dejarlo así:
                $paymentId = $paymentIds[0] ?? null;

                $sellerSku = $orderData['order_items'][0]['item']['seller_sku'] ?? null;
                $nombreProducto = $orderData['order_items'][0]['item']['title'] ?? null;
                $linkAmazon = $sellerSku ? "https://www.amazon.com/dp/{$sellerSku}" : null;
                $linkMl = "https://www.mercadolibre.com.ar/ventas/{$orderId}/detalle";

                // Obtener comisión ML con reintentos si es necesario
                $comisionMl = $this->getSaleFeeSafely($orderData, $orderId, $accessToken);

                $order = Order::create([
                    'nro_venta' => $orderId,
                    'payment_id' => $paymentId,
                    'tipo_venta' => 'ML',
                    'fecha_venta' => $orderData['date_closed'] ?? null,
                    'fecha_entrega' => $fechaEntrega,
                    'nombre_producto' => $nombreProducto,
                    'sku' => $sellerSku,
                    'link_ml' => $linkMl,
                    'link_amazon' => $linkAmazon,
                    'cantidad_unidades' => intval($orderData['order_items'][0]['quantity'] ?? 1),
                    'precio_venta' => $orderData['total_amount'] ?? null,
                    'saldo_mercadolibre' => $saldo_mercadolibre,
                    'comision_ml' => ($comisionMl > 0 ? (string)$comisionMl : null),
                    'aporte_ml' => $orderData['payments'][0]['coupon_amount'] ?? null,
                    'costo_envio' => $costoEnvio,
                    'impuestos' => $impuestos,
                    'cuit_comprador' => $billingData['buyer']['billing_info']['identification']['number'] ?? null,
                    'nombre_destinatario' => trim(($billingData['buyer']['billing_info']['name'] ?? '') . ' ' . ($billingData['buyer']['billing_info']['last_name'] ?? '')),
                    'direccion_cliente' => trim(($billingData['buyer']['billing_info']['address']['street_name'] ?? '') . ' ' . ($billingData['buyer']['billing_info']['address']['street_number'] ?? '')),
                    'ciudad' => $billingData['buyer']['billing_info']['address']['city_name'] ?? '',
                    'provincia' => $billingData['buyer']['billing_info']['address']['state']['name'] ?? '',
                    'codigo_postal' => $billingData['buyer']['billing_info']['address']['zip_code'] ?? '',
                    'estado_orden' => $orderData['status'] ?? null,
                ]);

                Log::info("Orden {$orderId} guardada exitosamente.");

                // 1. Enviar la orden a Flokzu
                $identifier = $flokzuService->enviarOrden($order);
                if ($identifier) {
                    $order->update(['flokzu_identifier' => $identifier]);
                }
                
                // 2. Enviar mensaje al comprador
                $this->enviarMensajeComprador($order, $orderData, $accessToken);

                // 3. Actualizar stock específico
                $this->actualizarStockProductoEspecifico($order, $authService);

                return;
            }

            // Si la orden NO existe y el estado NO es 'paid', ignorar
            Log::info("Orden {$orderId} ignorada: estado no es 'paid' (estado actual: {$orderStatus})");
            return;

        } catch (\Exception $e) {
            Log::error("Excepción al procesar orden {$orderId}: " . $e->getMessage());
        }
    }

    /**
     * Obtiene el valor sale_fee con reintentos si es necesario
     * 
     * @param array $orderData
     * @param string $orderId
     * @param string $accessToken
     * @return float
     */
    protected function getSaleFeeSafely(array $orderData, string $orderId, string $accessToken): float
    {
        // Intentar obtener sale_fee del objeto de orden inicial
        $saleFee = floatval($orderData['order_items'][0]['sale_fee'] ?? 0);
        $cantidadUnidades = intval($orderData['order_items'][0]['quantity'] ?? 1);
        $comisionMl = $saleFee * $cantidadUnidades;
        
        // Si ya tenemos un valor positivo, lo devolvemos directamente
        if ($comisionMl > 0) {
            return $comisionMl;
        }
        
        // Si no hay comisión (0 o null), reintentamos con delay
        Log::info("Valor de comisión ML inicial es 0 o nulo. Reintentando en 60 segundos...");
        
        // Esperar antes de reintentar
        sleep(60);
        
        // Obtener datos actualizados de la orden
        $refreshOrderResponse = Http::withToken($accessToken)
            ->get("https://api.mercadolibre.com/orders/{$orderId}");
            
        if ($refreshOrderResponse->successful()) {
            $refreshedOrderData = $refreshOrderResponse->json();
            $saleFee = floatval($refreshedOrderData['order_items'][0]['sale_fee'] ?? 0);
            $cantidadUnidades = intval($refreshedOrderData['order_items'][0]['quantity'] ?? 1);
            $comisionMl = $saleFee * $cantidadUnidades;
            
            if ($comisionMl > 0) {
                Log::info("Comisión ML obtenida en segundo intento: {$comisionMl}");
            } else {
                Log::warning("No se pudo obtener un valor válido de comisión ML después del reintento");
            }
        } else {
            Log::warning("Error al reintentar obtener sale_fee: " . $refreshOrderResponse->body());
        }
        
        return $comisionMl;
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

    /**
     * Actualiza el stock a 2 para el producto MLA1940860870 después de cada venta
     */
    protected function actualizarStockProductoEspecifico(Order $order, MercadoLibreAuthService $authService): void
    {
        try {
            // Desactivado temporalmente por decisión operativa
            return;

            // Verificar si el SKU corresponde al producto específico
            if ($order->sku !== 'F4200') {
                return;
            }

            $itemId = 'MLA1940860870';
            $accessToken = $authService->getAccessToken();

            $response = Http::withToken($accessToken)
                ->put("https://api.mercadolibre.com/items/{$itemId}", [
                    'available_quantity' => 2
                ]);

            if ($response->successful()) {
                Log::info("Stock actualizado exitosamente para producto {$itemId} a 2 unidades después de la venta {$order->nro_venta}");
            } else {
                Log::error("Error al actualizar stock del producto {$itemId}: " . $response->body());
            }

        } catch (\Exception $e) {
            Log::error("Excepción al actualizar stock del producto específico: " . $e->getMessage());
        }
    }

    /**
     * Envía un mensaje al comprador usando el recurso messages de MercadoLibre
     * URL: /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale
     * Límite: 350 caracteres, encoding ISO-8859-1, 500 rpm para recursos de escritura
     */
    protected function enviarMensajeComprador(Order $order, array $orderData, string $accessToken): void
    {
        try {
            $sellerId = $orderData['seller']['id'] ?? null;
            $buyerId = $orderData['buyer']['id'] ?? null;
            $orderId = $order->nro_venta;
            
            // Usar pack_id si existe, sino usar order_id
            $packId = $orderData['pack_id'] ?? $orderId;

            if (!$sellerId || !$buyerId) {
                Log::warning("No se puede enviar mensaje: sellerId o buyerId faltante para orden {$orderId}");
                return;
            }

            // Obtener nombre del comprador
            $buyerName = $orderData['buyer']['first_name'] ?? $orderData['buyer']['nickname'] ?? 'Cliente';
            $producto = $order->nombre_producto ?? ($orderData['order_items'][0]['item']['title'] ?? '');

            // Construir mensaje base sin el producto
            $textoBase = "¡Hola {$buyerName}! ¡Bienvenido a Tienda Lo Quiero Acá! "
                . "Confirmamos tu compra de "
                . ". Traemos tus productos desde EE.UU, rápido y seguro. "
                . "El precio es final, todos los costos incluidos. "
                . "¡Gracias por confiar en nosotros!";

            // Calcular espacio disponible para el nombre del producto
            $caracteresDisponibles = 350 - mb_strlen($textoBase, 'UTF-8');
            
            // Recortar el nombre del producto si es necesario
            if (mb_strlen($producto, 'UTF-8') > $caracteresDisponibles) {
                $producto = mb_substr($producto, 0, $caracteresDisponibles - 3, 'UTF-8');
            }

            // Construir mensaje final
            $texto = "¡Hola {$buyerName}! ¡Bienvenido/a a Tienda Lo Quiero Acá! "
                . "Confirmamos tu compra de {$producto}. "
                . "Traemos tus productos desde EE.UU, rápido y seguro. "
                . "El precio es final, todos los costos incluidos. "
                . "¡Gracias por confiar en nosotros!";

            $payload = [
                'from' => [
                    'user_id' => (string)$sellerId
                ],
                'to' => [
                    'user_id' => (string)$buyerId,
                    'resource' => 'orders',
                    'resource_id' => (string)$orderId,
                    'site_id' => 'MLA'
                ],
                'text' => $texto
            ];

            $url = "https://api.mercadolibre.com/messages/packs/{$packId}/sellers/{$sellerId}?tag=post_sale";

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                $messageId = $responseData['id'] ?? 'N/A';
                $moderationStatus = $responseData['message_moderation']['status'] ?? 'unknown';
                
                Log::info("Mensaje enviado exitosamente al comprador {$buyerId} para orden {$orderId}. " . 
                         "Message ID: {$messageId}, Moderación: {$moderationStatus}");
            } else {
                $statusCode = $response->status();
                $errorBody = $response->body();
                
                // Manejar errores específicos
                if ($statusCode === 403) {
                    $errorData = $response->json();
                    $errorCode = $errorData['code'] ?? 'unknown';
                    
                    if ($errorCode === 'blocked_conversation_send_message_forbidden') {
                        Log::warning("Mensajería bloqueada para orden cancelada {$orderId}");
                    } else {
                        Log::warning("Acceso denegado al enviar mensaje para orden {$orderId}: {$errorBody}");
                    }
                } elseif ($statusCode === 400) {
                    Log::warning("Error en formato de mensaje para orden {$orderId}: {$errorBody}");
                } else {
                    Log::error("Error HTTP {$statusCode} al enviar mensaje para orden {$orderId}: {$errorBody}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Excepción al enviar mensaje al comprador para orden {$order->nro_venta}: " . $e->getMessage());
        }
    }
}