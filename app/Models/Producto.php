<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'productos';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    public $timestamps = false;
    
    protected $fillable = [
        'id_publicacion',
        'situacion_catalogo',
        'id_publicacion_relacionada',
        'id_producto_catalogo',
        'categoria',
        'titulo',
        'largo',
        'descripcion',
        'precio',
        'iva',
        'impuesto_interno',
        'sku',
        'estado',
        'stock',
        'disponibilidad_de_stock',
        'tipo_de_publicacion',
        'cuotas',
        'condicion',
        'envio_gratis',
        'precio_envio_gratis',
        'modo_envio',
        'metodo_envio',
        'retira_en_persona',
        'envio_flex',
        'garantia',
        'fecha_creacion',
        'ultima_actualizacion',
        'resultado',
        'resultado_observaciones',
        'imagen_1',
        'imagen_2',
        'imagen_3',
        'imagen_4',
        'imagen_5',
        'imagen_6',
        'imagen_7',
        'imagen_8',
        'imagen_9',
        'imagen_10',
        'atributos',
        'variaciones',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'atributos' => 'array',
        'variaciones' => 'array',
        'fecha_creacion' => 'datetime',
        'ultima_actualizacion' => 'datetime',
        'envio_gratis' => 'boolean',
        'retira_en_persona' => 'boolean',
        'envio_flex' => 'boolean',
        'precio' => 'decimal:2',
        'precio_envio_gratis' => 'decimal:2',
    ];
}