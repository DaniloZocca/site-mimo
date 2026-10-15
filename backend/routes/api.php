<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\Conta\AccountController;
use App\Http\Controllers\Api\Conta\CustomerOrdersController;
use App\Http\Controllers\Api\Conta\EmailVerificationController;
use App\Http\Controllers\Api\Conta\PasswordResetController;
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
// O 3º parâmetro do throttle é o prefixo do contador: cada grupo tem o seu,
// para que rastreio e frete não consumam o limite do checkout (bug #18).
Route::middleware(['throttle:10,1,checkout'])->group(function (): void {
    Route::post('/checkout', CheckoutController::class);
});

// =========================================================================
// 3. Rastreamento Público de Encomendas (Anti-IDOR / LGPD / Throttle: 10,1)
// =========================================================================
Route::middleware(['throttle:10,1,tracking'])->group(function (): void {
    Route::post('/orders/track', OrderTrackingController::class);
});

// =========================================================================
// 4. Cotação de Frete Dinâmica & Logística (Throttle: 30,1)
// =========================================================================
Route::middleware(['throttle:30,1,shipping'])->group(function (): void {
    Route::post('/shipping/calculate', [ShippingController::class, 'calculate']);
});

// =========================================================================
// 5. Webhooks de Pagamento (Mercado Pago - HMAC Validated)
// =========================================================================
Route::post('/webhooks/mercadopago', MercadoPagoWebhookController::class);

// =========================================================================
// 6. Conta de cliente (M-26): sessão em cookie httpOnly + CSRF (grupo web)
// =========================================================================
// O front manda credentials: 'include' e o cabeçalho X-XSRF-TOKEN (cookie XSRF-TOKEN, que
// toda resposta deste grupo devolve). Só estas rotas usam sessão; checkout, frete e rastreio não.
Route::prefix('conta')->middleware(['web'])->group(function (): void {
    Route::post('/register', [AccountController::class, 'register'])->middleware('throttle:conta-register');
    Route::post('/login', [AccountController::class, 'login'])->middleware('throttle:conta-login');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:conta-forgot');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:conta-reset');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:conta-verify')
        ->name('conta.verification.verify');

    Route::middleware(['auth:customer', 'auth.session'])->group(function (): void {
        Route::get('/me', [AccountController::class, 'me'])->middleware('throttle:conta');
        Route::post('/logout', [AccountController::class, 'logout'])->middleware('throttle:conta');
        Route::get('/orders', CustomerOrdersController::class)->middleware('throttle:conta');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:conta-resend');
    });
});
