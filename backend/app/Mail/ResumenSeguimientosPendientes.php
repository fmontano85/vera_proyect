<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Seccion 3.8: resumen diario agrupado (un correo por tenant y
 * destinatario, no uno por subject). Solo recuerda que toca revisar - no
 * afirma nada sobre las personas (seccion 1: el sistema no concluye, el
 * analista resuelve). Recibe alertas, no subjects: la fecha que se
 * muestra es el vencimiento de la alerta, no la proxima fecha actual.
 */
class ResumenSeguimientosPendientes extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Alert>  $alertas  con 'alertable' (Subject) cargado
     * @param  int  $siguenVencidos  subjects que siguen vencidos de dias anteriores
     */
    public function __construct(
        public readonly Collection $alertas,
        public readonly int $siguenVencidos,
    ) {}

    public function envelope(): Envelope
    {
        $total = $this->alertas->count();

        return new Envelope(
            subject: "VERA: {$total} ".($total === 1 ? 'seguimiento pendiente' : 'seguimientos pendientes'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.seguimientos-pendientes',
            with: [
                'urlPanel' => rtrim((string) config('vera.frontend_url'), '/').'/seguimientos',
            ],
        );
    }
}
