<?php

namespace App\Providers;

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
    }
}
