<?php

declare(strict_types=1);

use App\Enums\TipoAlerta;
use App\Jobs\DetectarSeguimientosVencidosJob;
use App\Jobs\SendAlertJob;
use App\Mail\ResumenSeguimientosPendientes;
use App\Models\Alert;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Seccion 3.8 del CLAUDE.md raiz: el job diario solo consulta la BD
 * propia (cero llamadas a Brave/Anthropic, seccion 7), crea una alerta
 * por subject+vencimiento (idempotente) y manda UN correo de resumen por
 * tenant a oficial_cumplimiento + admin (decision del usuario 2026-09-25),
 * solo los dias con vencimientos nuevos.
 */
function crearUsuarioAlertaConRol(Tenant $tenant, string $rol, string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

function subjectVencido(Tenant $tenant, string $vencimiento, array $atributos = []): Subject
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create($atributos);
    Subject::whereKey($subject->id)->update(['proximo_seguimiento_en' => $vencimiento]);
    tenancy()->end();

    return $subject;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Carbon::setTestNow('2026-09-25 15:00:00');
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('crea una alerta por subject vencido y encola el envio del tenant, sin llamadas HTTP externas', function () {
    Bus::fake([SendAlertJob::class]);
    $tenant = Tenant::create();
    $vencido = subjectVencido($tenant, '2026-09-25');
    subjectVencido($tenant, '2026-09-26'); // vence manana
    subjectVencido($tenant, '2026-09-01', ['activo' => false]);

    (new DetectarSeguimientosVencidosJob)->handle();

    tenancy()->initialize($tenant);
    $alerta = Alert::sole();
    expect($alerta->tipo)->toBe(TipoAlerta::SeguimientoPendiente)
        ->and($alerta->alertable_id)->toBe($vencido->id)
        ->and($alerta->vencimiento->toDateString())->toBe('2026-09-25')
        ->and($alerta->enviado_en)->toBeNull();
    tenancy()->end();

    Bus::assertDispatched(SendAlertJob::class, fn (SendAlertJob $job) => $job->tenantId === $tenant->id);
    Http::assertNothingSent();
});

it('es idempotente: correr el job dos veces no duplica la alerta', function () {
    Bus::fake([SendAlertJob::class]);
    $tenant = Tenant::create();
    subjectVencido($tenant, '2026-09-20');

    (new DetectarSeguimientosVencidosJob)->handle();
    (new DetectarSeguimientosVencidosJob)->handle();

    tenancy()->initialize($tenant);
    expect(Alert::count())->toBe(1);
    tenancy()->end();
    // Puede volver a encolar el envio (la alerta sigue sin enviar), pero
    // SendAlertJob es ShouldBeUnique por tenant y solo toma alertas con
    // enviado_en null: no hay doble correo.
});

it('no encola envio para un tenant sin vencimientos', function () {
    Bus::fake([SendAlertJob::class]);
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    Subject::factory()->create(['nivel_riesgo' => 'alto']);
    tenancy()->end();

    (new DetectarSeguimientosVencidosJob)->handle();

    Bus::assertNotDispatched(SendAlertJob::class);
});

it('usa el dia de El Salvador como corte, no el de UTC', function () {
    Bus::fake([SendAlertJob::class]);
    // 2026-09-26 03:00 UTC = 2026-09-25 21:00 en El Salvador.
    Carbon::setTestNow(Carbon::parse('2026-09-26 03:00:00', 'UTC'));
    $tenant = Tenant::create();
    subjectVencido($tenant, '2026-09-26');

    (new DetectarSeguimientosVencidosJob)->handle();

    tenancy()->initialize($tenant);
    expect(Alert::count())->toBe(0);
    tenancy()->end();
});

it('un vencimiento nuevo del mismo subject (tras marcar seguimiento) genera una alerta nueva', function () {
    Bus::fake([SendAlertJob::class]);
    $tenant = Tenant::create();
    $subject = subjectVencido($tenant, '2026-09-20');
    (new DetectarSeguimientosVencidosJob)->handle();

    // Se marca el seguimiento y, tiempo despues, vuelve a vencer.
    Carbon::setTestNow('2026-10-30 15:00:00');
    tenancy()->initialize($tenant);
    Subject::whereKey($subject->id)->update([
        'ultimo_seguimiento_en' => '2026-09-26 10:00:00',
        'proximo_seguimiento_en' => '2026-10-26',
    ]);
    tenancy()->end();
    (new DetectarSeguimientosVencidosJob)->handle();

    tenancy()->initialize($tenant);
    expect(Alert::count())->toBe(2);
    tenancy()->end();
});

it('envia el resumen a oficial y admin del tenant (un correo por destinatario, sin exponer direcciones ajenas), marca enviado y audita', function () {
    Mail::fake();
    $tenant = Tenant::create();
    crearUsuarioAlertaConRol($tenant, 'oficial_cumplimiento', 'oficial@t1.test');
    crearUsuarioAlertaConRol($tenant, 'admin', 'admin@t1.test');
    crearUsuarioAlertaConRol($tenant, 'analista', 'analista@t1.test');
    crearUsuarioAlertaConRol(Tenant::create(), 'oficial_cumplimiento', 'oficial@otro.test');
    subjectVencido($tenant, '2026-09-24', ['nombre_canonico' => 'Ana Lopez']);
    subjectVencido($tenant, '2026-09-25', ['nombre_canonico' => 'Luis Mena']);

    Bus::fake([SendAlertJob::class]);
    (new DetectarSeguimientosVencidosJob)->handle();
    (new SendAlertJob($tenant->id))->handle();

    Mail::assertSent(ResumenSeguimientosPendientes::class, 2);
    foreach (['oficial@t1.test', 'admin@t1.test'] as $email) {
        Mail::assertSent(ResumenSeguimientosPendientes::class, function (ResumenSeguimientosPendientes $mail) use ($email) {
            return $mail->hasTo($email)
                && count($mail->to) === 1
                && $mail->alertas->pluck('alertable.nombre_canonico')->sort()->values()->all() === ['Ana Lopez', 'Luis Mena'];
        });
    }
    Mail::assertNotSent(ResumenSeguimientosPendientes::class, fn ($mail) => $mail->hasTo('analista@t1.test') || $mail->hasTo('oficial@otro.test'));

    tenancy()->initialize($tenant);
    expect(Alert::whereNull('enviado_en')->count())->toBe(0)
        ->and(Activity::where('event', 'alertas_enviadas')->count())->toBe(1);
    tenancy()->end();
});

it('no reenvia alertas ya enviadas', function () {
    Mail::fake();
    $tenant = Tenant::create();
    crearUsuarioAlertaConRol($tenant, 'oficial_cumplimiento', 'oficial@t1.test');
    subjectVencido($tenant, '2026-09-24');

    Bus::fake([SendAlertJob::class]);
    (new DetectarSeguimientosVencidosJob)->handle();
    (new SendAlertJob($tenant->id))->handle();
    (new SendAlertJob($tenant->id))->handle();

    Mail::assertSent(ResumenSeguimientosPendientes::class, 1);
});

it('el resumen informa cuantos siguen vencidos de dias anteriores', function () {
    Mail::fake();
    $tenant = Tenant::create();
    crearUsuarioAlertaConRol($tenant, 'oficial_cumplimiento', 'oficial@t1.test');
    subjectVencido($tenant, '2026-09-20');
    Bus::fake([SendAlertJob::class]);
    (new DetectarSeguimientosVencidosJob)->handle();
    (new SendAlertJob($tenant->id))->handle();

    // Al dia siguiente vence otro; el primero sigue sin atender.
    Carbon::setTestNow('2026-09-26 15:00:00');
    subjectVencido($tenant, '2026-09-26');
    (new DetectarSeguimientosVencidosJob)->handle();
    (new SendAlertJob($tenant->id))->handle();

    Mail::assertSent(ResumenSeguimientosPendientes::class, 2);
    Mail::assertSent(ResumenSeguimientosPendientes::class, fn ($mail) => $mail->alertas->count() === 1 && $mail->siguenVencidos === 1);
});

it('si Detectar no crea alertas nuevas pero quedaron alertas sin enviar, reintenta el envio', function () {
    // Cola sync (phpunit.xml): SendAlertJob corre de verdad al encolarse.
    Mail::fake();
    $tenant = Tenant::create();
    subjectVencido($tenant, '2026-09-24');

    // Dia 1: el tenant no tiene oficial ni admin - la alerta queda pendiente.
    (new DetectarSeguimientosVencidosJob)->handle();
    Mail::assertNothingSent();

    // Dia 2: ya hay oficial y no vence nada nuevo, pero la pendiente sale.
    crearUsuarioAlertaConRol($tenant, 'oficial_cumplimiento', 'oficial@t1.test');
    Carbon::setTestNow('2026-09-26 15:00:00');
    (new DetectarSeguimientosVencidosJob)->handle();

    Mail::assertSent(ResumenSeguimientosPendientes::class, 1);
    tenancy()->initialize($tenant);
    expect(Alert::count())->toBe(1)
        ->and(Alert::whereNull('enviado_en')->count())->toBe(0);
    tenancy()->end();
});

it('no crea una segunda alerta si la fecha de un subject ya vencido se recalcula y sigue vencida (mismo ciclo)', function () {
    Bus::fake([SendAlertJob::class]);
    $tenant = Tenant::create();
    $subject = subjectVencido($tenant, '2026-09-20');
    (new DetectarSeguimientosVencidosJob)->handle();

    // Ej. el admin cambio los dias del nivel: la fecha cambia pero sigue vencida.
    tenancy()->initialize($tenant);
    Subject::whereKey($subject->id)->update(['proximo_seguimiento_en' => '2026-09-10']);
    tenancy()->end();
    Carbon::setTestNow('2026-09-26 15:00:00');
    (new DetectarSeguimientosVencidosJob)->handle();

    tenancy()->initialize($tenant);
    expect(Alert::count())->toBe(1);
    tenancy()->end();
});

it('no incluye en el correo alertas de subjects ya atendidos o desactivados antes del envio, y las descarta', function () {
    Mail::fake();
    $tenant = Tenant::create();
    crearUsuarioAlertaConRol($tenant, 'oficial_cumplimiento', 'oficial@t1.test');
    $atendido = subjectVencido($tenant, '2026-09-24', ['nombre_canonico' => 'Ya Atendido']);
    $inactivo = subjectVencido($tenant, '2026-09-24', ['nombre_canonico' => 'Inactivo']);
    subjectVencido($tenant, '2026-09-24', ['nombre_canonico' => 'Sigue Pendiente']);

    Bus::fake([SendAlertJob::class]);
    (new DetectarSeguimientosVencidosJob)->handle();

    // Antes de que corra el envio, uno se atiende y otro se desactiva.
    Carbon::setTestNow('2026-09-25 16:00:00');
    tenancy()->initialize($tenant);
    Subject::whereKey($atendido->id)->update(['ultimo_seguimiento_en' => now(), 'proximo_seguimiento_en' => '2026-10-25']);
    Subject::whereKey($inactivo->id)->update(['activo' => false]);
    tenancy()->end();

    (new SendAlertJob($tenant->id))->handle();

    Mail::assertSent(ResumenSeguimientosPendientes::class, fn ($mail) => $mail->alertas->pluck('alertable.nombre_canonico')->all() === ['Sigue Pendiente']);
    tenancy()->initialize($tenant);
    expect(Alert::count())->toBe(1);
    tenancy()->end();
});

it('si el envio falla no marca las alertas como enviadas (el reintento las vuelve a tomar)', function () {
    $tenant = Tenant::create();
    crearUsuarioAlertaConRol($tenant, 'oficial_cumplimiento', 'oficial@t1.test');
    subjectVencido($tenant, '2026-09-24');
    Bus::fake([SendAlertJob::class]);
    (new DetectarSeguimientosVencidosJob)->handle();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caido'));

    expect(fn () => (new SendAlertJob($tenant->id))->handle())->toThrow(RuntimeException::class);

    tenancy()->initialize($tenant);
    expect(Alert::whereNull('enviado_en')->count())->toBe(1);
    tenancy()->end();
});

it('SendAlertJob es unico por tenant (no corren dos envios simultaneos del mismo tenant)', function () {
    $job = new SendAlertJob('tenant-x');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('tenant-x');
});

it('si el tenant no tiene oficial ni admin no envia y deja las alertas pendientes de envio', function () {
    Mail::fake();
    $tenant = Tenant::create();
    subjectVencido($tenant, '2026-09-24');

    Bus::fake([SendAlertJob::class]);
    (new DetectarSeguimientosVencidosJob)->handle();
    (new SendAlertJob($tenant->id))->handle();

    Mail::assertNothingSent();
    tenancy()->initialize($tenant);
    expect(Alert::whereNull('enviado_en')->count())->toBe(1);
    tenancy()->end();
});

it('el correo renderiza con los nombres y el enlace al panel', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Ana Lopez', 'nivel_riesgo' => 'alto']);
    $alerta = Alert::create([
        'tipo' => TipoAlerta::SeguimientoPendiente,
        'alertable_type' => $subject->getMorphClass(),
        'alertable_id' => $subject->id,
        'vencimiento' => '2026-09-20',
    ])->load('alertable');
    tenancy()->end();

    $html = (new ResumenSeguimientosPendientes(collect([$alerta]), 3))->render();

    // La fecha que se muestra es la del vencimiento de la alerta, no la
    // proxima fecha actual del subject.
    expect($html)->toContain('Ana Lopez')
        ->toContain('20/09/2026')
        ->toContain('3')
        ->toContain(rtrim((string) config('vera.frontend_url'), '/').'/seguimientos');
});

it('queda programado diariamente a las 07:00 hora de El Salvador', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, DetectarSeguimientosVencidosJob::class));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('0 7 * * *')
        ->and($evento->timezone)->toBe('America/El_Salvador');
});
