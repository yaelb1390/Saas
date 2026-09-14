<?php

declare(strict_types=1);

namespace App\Modules\Printing\Services;

use App\Models\User;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * El historial de impresiones. Cada trabajo —salga bien, falle o se cancele— queda una fila: es lo
 * que responde «¿quién imprimió esto, cuándo y en cuál impresora?» cuando algo no cuadra en caja.
 */
final class PrintJobLogger
{
    public function registrar(
        User $usuario,
        string $documentType,
        PrintJobStatus $estado,
        ?Printer $printer = null,
        ?PrintTemplate $template = null,
        ?Model $referencia = null,
        int $copias = 1,
        ?string $paperSize = null,
        ?string $errorMessage = null,
    ): PrintJob {
        return PrintJob::create([
            'user_id' => $usuario->id,
            'printer_id' => $printer?->id,
            'template_id' => $template?->id,
            'document_type' => $documentType,
            'reference_type' => $referencia?->getMorphClass(),
            'reference_id' => $referencia?->getKey(),
            'copies' => max(1, $copias),
            'paper_size' => $paperSize,
            'status' => $estado,
            'error_message' => $errorMessage,
            'printed_at' => $estado === PrintJobStatus::Printed ? now() : null,
        ]);
    }
}
