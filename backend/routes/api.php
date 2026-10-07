<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\MercadoPagoWebhookController;
use App\Http\Controllers\Api\OrderTrackingController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ShippingController;
use App\Models\Category;
use App\Models\Color;
use App\Models\Size;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - MIMOO LAB 3D Studio
|--------------------------------------------------------------------------
*/

// =========================================================================
// 1. Catálogo e Produtos (Público com Cache/Eager Loading)
// =========================================================================
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);

Route::get('/categories', function () {
    return response()->json(
        Category::withCount('products')->get()
    );
});

// colors e sizes não têm coluna is_active: as rotas listam todos os registros
// (decisão pendente em docs/coordenacao.md; a disponibilidade da cor vem em in_stock).
Route::get('/colors', function () {
    return response()->json(
        Color::orderBy('name')->get()
    );
});

Route::get('/sizes', function () {
    return response()->json(
        Size::orderBy('name')->get()
    );
});

// =========================================================================
// 2. Checkout & Pagamentos (Rate Limited: 10 req/min)
// =========================================================================
Route::middleware(['throttle:10,1'])->group(function (): void {
    Route::post('/checkout', CheckoutController::class);
});

// =========================================================================
// 3. Rastreamento Público de Encomendas (Anti-IDOR / LGPD / Throttle: 10,1)
// =========================================================================
Route::middleware(['throttle:10,1'])->group(function (): void {
    Route::post('/orders/track', OrderTrackingController::class);
});

// =========================================================================
// 4. Cotação de Frete Dinâmica & Logística (Throttle: 30,1)
// =========================================================================
Route::middleware(['throttle:30,1'])->group(function (): void {
    Route::post('/shipping/calculate', [ShippingController::class, 'calculate']);
});

// =========================================================================
// 5. Webhooks de Pagamento (Mercado Pago - HMAC Validated)
// =========================================================================
Route::post('/webhooks/mercadopago', MercadoPagoWebhookController::class);
