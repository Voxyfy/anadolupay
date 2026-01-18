<?php

use Illuminate\Support\Facades\Route;
use Voxyfy\AnadoluPay\Http\Controllers\WebhookController;

Route::post('/anadolupay/webhook/{driver}', [WebhookController::class, 'handle'])
    ->name('anadolupay.webhook');
