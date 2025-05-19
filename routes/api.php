<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NotificationController;

Route::post('/meli/webhook', [NotificationController::class, 'handle']);