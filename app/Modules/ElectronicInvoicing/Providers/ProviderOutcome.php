<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

/**
 * Resultado normalizado de una operación con la DGII o un proveedor. Cada proveedor traduce sus
 * respuestas a esto; el resto de BMIA nunca ve el formato propio de cada uno.
 */
enum ProviderOutcome: string
{
    /** Recibido con TrackId; el resultado se conoce después (DGII «3 En proceso»). */
    case Received = 'received';
    case Accepted = 'accepted';
    case AcceptedConditional = 'accepted_conditional';
    case Rejected = 'rejected';
    /** La consulta no encontró el TrackId (DGII «0»). */
    case NotFound = 'not_found';
    /** Sigue en proceso (consulta). */
    case InProcess = 'in_process';
    /** Fallo pasajero: red, tiempo agotado, 5xx. Se reintenta. */
    case TransientError = 'transient_error';
    /** Fallo que no se arregla reintentando: credenciales, petición mal formada. */
    case PermanentError = 'permanent_error';
    case NotConfigured = 'not_configured';
}
