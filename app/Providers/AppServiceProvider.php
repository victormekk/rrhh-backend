<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        // Cierre por inactividad: Sanctum evalúa esto ANTES de actualizar
        // last_used_at, así que se compara contra la petición anterior.
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            $ultimoUso = $token->last_used_at ?? $token->created_at;

            return $isValid
                && $ultimoUso->gt(now()->subMinutes((int) config('sanctum.inactividad', 120)));
        });
    }
}
