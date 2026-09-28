<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\TenantPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Database\Models\Tenant;

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
        // Tenant es un modelo de stancl/tenancy: el autodescubrimiento de
        // policies de Laravel (App\Policies\{Model}Policy) no lo encuentra
        // solo, hay que registrarlo explicito.
        Gate::policy(Tenant::class, TenantPolicy::class);

        // Bitacora del tenant: solo su admin (seccion 3.9, punto 7). La
        // global del superadmin usa TenantPolicy::gestionar.
        Gate::define('ver-bitacora-tenant', fn (User $user) => $user->hasRole('admin'));

        // Terminos y contrato: los acepta el admin en nombre del tenant (seccion 3.9, punto 1).
        Gate::define('aceptar-documentos-legales', fn (User $user) => $user->hasRole('admin'));
    }
}
