<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;

class TenancyServiceProvider extends ServiceProvider
{
    public function events()
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            // VERA usa base de datos unica (seccion 3.1 del CLAUDE.md raiz): no se
            // provisiona una BD por tenant, por eso CreateDatabase/MigrateDatabase
            // quedan deshabilitados. Nada que ejecutar aqui al crear un tenant por
            // ahora; agregar jobs propios si hace falta preparar algo (R2, etc.).
            Events\TenantCreated::class => [],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            // Base de datos unica: no hay BD de tenant que borrar.
            Events\TenantDeleted::class => [],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register()
    {
        //
    }

    /**
     * VERA resuelve tenancy por Sanctum + App\Http\Middleware\InitializeTenancyFromAuthenticatedUser
     * (alias "tenant" en bootstrap/app.php), no por dominio/subdominio. Por
     * eso no hay aqui middleware de identificacion de dominio ni routes/tenant.php:
     * las rutas de negocio viven en routes/api.php bajo ese middleware.
     */
    public function boot()
    {
        $this->bootEvents();
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }
}
