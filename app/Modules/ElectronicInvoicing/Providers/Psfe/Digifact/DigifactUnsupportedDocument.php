<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe\Digifact;

use RuntimeException;

/** Un documento que el conector de Digifact todavía no sabe enviar (sin ejemplo oficial en su documentación). */
final class DigifactUnsupportedDocument extends RuntimeException {}
