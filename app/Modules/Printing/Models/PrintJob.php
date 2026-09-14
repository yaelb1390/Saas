<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Support\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una fila del historial de impresiones: quién imprimió qué, en cuál impresora, cuándo y con qué
 * resultado. No es Auditable —es EL registro en sí, auditar un log sería llevar un log del log— y no
 * lleva SoftDeletes: el historial no se borra, solo se filtra.
 */
final class PrintJob extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'printer_id',
        'template_id',
        'document_type',
        'reference_type',
        'reference_id',
        'copies',
        'paper_size',
        'status',
        'error_message',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PrintJobStatus::class,
            'printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * @return BelongsTo<PrintTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PrintTemplate::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function tipoLegible(): string
    {
        return DocumentType::label((string) $this->document_type);
    }
}
