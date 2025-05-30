<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'tipo_venta',
        'nro_venta',
        'fecha_venta',
        'fecha_entrega',
        'nombre_producto',
        'link_ml',
        'cantidad_unidades',
        'precio_venta',
        'saldo_mercadolibre',
        'comision_ml',
        'aporte_ml',
        'costo_envio',
        'impuestos',
        'cuit_comprador',
        'nombre_destinatario',
        'direccion_cliente',
        'ciudad',
        'provincia',
        'codigo_postal',
    ];

    protected $casts = [
        'fecha_venta' => 'datetime',
        'fecha_entrega' => 'datetime',
    ];
}