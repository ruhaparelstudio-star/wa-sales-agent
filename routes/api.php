<?php

use App\Modules\WhatsApp\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Baileys webhook — no auth middleware, validated by X-Baileys-Secret header inside controller
Route::post('/whatsapp/webhook', [WebhookController::class, 'handle'])
    ->name('whatsapp.webhook');
