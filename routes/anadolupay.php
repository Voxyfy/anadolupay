<?php

use Illuminate\Support\Facades\Route;
use Voxyfy\AnadoluPay\Http\Controllers\WebhookController;

Route::post('/anadolupay/webhook/{driver}', [WebhookController::class, 'handle'])
    ->middleware(config('anadolupay.webhook.middleware', ['throttle:60,1']))
    ->name('anadolupay.webhook');
