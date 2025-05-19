<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_venta')->default('ML');
            $table->bigInteger('nro_venta')->unique();
            $table->timestamp('fecha_venta')->nullable();
            $table->timestamp('fecha_entrega')->nullable();
            $table->string('nombre_producto')->nullable();
            $table->string('link_ml')->nullable();
            $table->integer('cantidad_unidades')->default(1);
            $table->decimal('precio_venta', 10, 2)->nullable();
            $table->decimal('saldo_mercadolibre', 10, 2)->nullable();
            $table->decimal('comision_ml', 10, 2)->nullable();
            $table->decimal('costo_envio', 10, 2)->nullable();
            $table->decimal('impuestos', 10, 2)->nullable();
            $table->string('cuit_comprador')->nullable();
            $table->string('nombre_destinatario')->nullable();
            $table->string('direccion_cliente')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('provincia')->nullable();
            $table->string('codigo_postal')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
};
