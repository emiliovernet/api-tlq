<?php

namespace App\Http\Controllers;

// use App\Models\Order;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;
// use App\Services\MercadoLibreAuthService;
// use App\Services\FlokzuService;
// use App\Jobs\ProcesarNotificacionMercadoLibre;

// class NotificationController extends Controller
// {
//     public function handle(Request $request, MercadoLibreAuthService $authService)
//     {
//         // Loguea la notificación recibida para depuración
//         Log::info('Notificación recibida:', $request->all());

//         $response = response()->json(['status' => 'received'], 200);

//         // Verifica que sea una notificación de orders_v2 y procesa después de la respuesta
//         if ($request->input('topic') === 'orders_v2') {
//             $orderId = str_replace('/orders/', '', $request->input('resource'));
//             $userId = $request->input('user_id');
//             $applicationId = $request->input('application_id');

//             // Procesamiento síncrono después de enviar la respuesta
//             $this->fetchAndSaveOrderData($orderId, $userId, $applicationId, $authService);
//         }
//         return $response;
//     }

//     private function fetchAndSaveOrderData($orderId, $userId, $applicationId, MercadoLibreAuthService $authService)
//     {
//         try {
//             $accessToken = $authService->getAccessToken();
    
//             $response = Http::withHeaders([
//                 'Authorization' => "Bearer $accessToken",
//             ])->get("https://api.mercadolibre.com/orders/{$orderId}");
    
//             if ($response->successful()) {
//                 $order = $response->json();
    
//                 $item = $order['order_items'][0]['item'] ?? [];
//                 $shipping = $order['shipping'] ?? [];
//                 $payment = $order['payments'][0] ?? [];
//                 $buyer = $order['buyer'] ?? [];
    
//                 $direccion = $shipping['receiver_address'] ?? null;
    
//                 $nuevaOrden = Order::updateOrCreate(
//                     ['nro_venta' => $order['id']],
//                     [
//                         'tipo_venta' => 'ML',
//                         'fecha_venta' => $order['date_created'] ?? null,
//                         'fecha_entrega' => optional($shipping)['estimated_delivery_final'] ?? null,
//                         'nombre_producto' => $item['title'] ?? null,
//                         'link_ml' => "https://www.mercadolibre.com.ar/ventas/{$order['id']}/detalle",
//                         'cantidad_unidades' => $order['order_items'][0]['quantity'] ?? 1,
//                         'precio_venta' => $order['order_items'][0]['unit_price'] ?? 0,
//                         'saldo_mercadolibre' => $payment['total_paid_amount'] ?? 0,
//                         'comision_ml' => $order['order_items'][0]['sale_fee'] ?? 0,
//                         'costo_envio' => $payment['shipping_cost'] ?? null,
//                         'impuestos' => $payment['taxes_amount'] ?? 0,
//                         'cuit_comprador' => $buyer['billing_info']['doc_number'] ?? null,
//                         'nombre_destinatario' => trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')),
//                         'direccion_cliente' => optional($direccion)['street_name'] . ' ' . optional($direccion)['street_number'],
//                         'ciudad' => optional($direccion)['city']['name'] ?? null,
//                         'provincia' => optional($direccion)['state']['name'] ?? null,
//                         'codigo_postal' => optional($direccion)['zip_code'] ?? null,
//                     ]
//                 );
    
//                 Log::info("Orden {$orderId} guardada exitosamente.");

//                 // Disparo a Flokzu
//                 app(FlokzuService::class)->enviarOrden($order);
    
               
//             } else {
//                 Log::error("Error al obtener orden {$orderId}: " . $response->body());
//             }
//         } catch (\Exception $e) {
//             Log::error("Excepción al procesar orden {$orderId}: " . $e->getMessage());
//         }
//     }
// }

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcesarNotificacionMercadoLibre;

class NotificationController extends Controller
{
    public function handle(Request $request)
    {
        // Loguea la notificación recibida
        Log::info('Notificación recibida de MercadoLibre:', $request->all());

        // Siempre responde 200 de inmediato
        $response = response()->json(['status' => 'received'], 200);

        // Procesar solo si es una notificación de orden
        if ($request->input('topic') === 'orders_v2') {
            $orderId = str_replace('/orders/', '', $request->input('resource'));
            $userId = $request->input('user_id');
            $applicationId = $request->input('application_id');

            // Dispara el job para procesar la orden en segundo plano
            dispatch(new ProcesarNotificacionMercadoLibre($orderId, $userId, $applicationId));
        }

        return $response;
    }
}