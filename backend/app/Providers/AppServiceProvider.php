<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // 1. Garantia e Integridade da pasta temporária do Livewire 3 e uploads públicos
        $livewireTmp = storage_path('app/livewire-tmp');
        if (! File::exists($livewireTmp)) {
            File::makeDirectory($livewireTmp, 0775, true, true);
        }

        $productsDir = storage_path('app/public/products');
        if (! File::exists($productsDir)) {
            File::makeDirectory($productsDir, 0775, true, true);
        }

        // 1. Resolução dinâmica de Root URL e porta local (:8000) para prevenir 403 / CORS em URLs assinadas do Livewire
        if (! empty(config('app.url'))) {
            if (app()->isProduction() || str_starts_with(config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }

            // Em desenvolvimento local, sincroniza dinamicamente com o Host da requisição (localhost vs 127.0.0.1)
            if (app()->environment('local', 'testing') && ! app()->runningInConsole()) {
                $host = Request::server('HTTP_HOST');
                if ($host) {
                    $scheme = Request::isSecure() ? 'https' : 'http';
                    URL::forceRootUrl("{$scheme}://{$host}");
                }
            } elseif (! app()->runningInConsole()) {
                URL::forceRootUrl(config('app.url'));
            }
        }

        // 2. Listeners de app/Listeners são registrados pela descoberta automática do Laravel 11
        // (não repetir com Event::listen aqui: cada listener rodaria duas vezes).
    }
}
