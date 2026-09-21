<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Bootstrap de datos de desarrollo para probar el pipeline de la seccion
 * 3.4 por HTTP sin frontend todavia (Fase 1 en construccion): crea un
 * tenant, un usuario oficial_cumplimiento con token Sanctum, una Source
 * tipo=cse activa y un Subject de prueba, e imprime los comandos curl
 * listos para copiar y pegar. No usar en produccion.
 */
class CrearDemo extends Command
{
    protected $signature = 'vera:demo {nombre_sujeto=Juan Perez}';

    protected $description = 'Crea tenant + usuario + token + source cse + subject de prueba para probar el pipeline por HTTP';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando es solo para desarrollo, no correrlo en produccion.');

            return self::FAILURE;
        }

        (new RoleSeeder())->run();

        $tenant = Tenant::create();

        $user = User::factory()->create([
            'name' => 'Oficial de Cumplimiento (demo)',
            'email' => 'demo+' . $tenant->id . '@vera.test',
        ]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole('oficial_cumplimiento');

        $token = $user->createToken('demo')->plainTextToken;

        $source = Source::firstOrCreate(
            ['nombre' => 'Medios salvadorenses (CSE)', 'tipo' => 'cse'],
            ['config' => [], 'activo' => true]
        );

        tenancy()->initialize($tenant);
        $subject = Subject::create([
            'tipo' => 'natural',
            'nombre_canonico' => $this->argument('nombre_sujeto'),
        ]);
        tenancy()->end();

        $this->newLine();
        $this->info('Demo creada:');
        $this->line("  tenant_id:  {$tenant->id}");
        $this->line("  usuario:    {$user->email}");
        $this->line("  token:      {$token}");
        $this->line("  source_id:  {$source->id} (cse, activo)");
        $this->line("  subject_id: {$subject->id} ({$subject->nombre_canonico})");

        $base = config('app.url');

        $this->newLine();
        $this->info('Comandos para probar (ajusta el host si no es ' . $base . '):');
        $this->line("curl -s -H \"Authorization: Bearer {$token}\" -H \"Accept: application/json\" \\");
        $this->line("  -X POST {$base}/api/subjects/{$subject->id}/buscar");
        $this->newLine();
        $this->line('# Espera unos segundos a que Horizon procese el pipeline, luego:');
        $this->line("curl -s -H \"Authorization: Bearer {$token}\" -H \"Accept: application/json\" \\");
        $this->line("  {$base}/api/subjects/{$subject->id}/matches");

        return self::SUCCESS;
    }
}
