<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Jobs\ProcesarNotificacionMercadoLibre;

class NotificationController extends Controller
{
    public function handle(Request $request)
    {
        // Despacha el job con todos los datos del request
        dispatch(new ProcesarNotificacionMercadoLibre($request->all()));

        // Responde inmediatamente
        return response()->json(['status' => 'received'], 200);
    }
}