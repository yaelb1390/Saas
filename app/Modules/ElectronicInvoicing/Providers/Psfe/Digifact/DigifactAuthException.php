<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe\Digifact;

use RuntimeException;

/** Digifact no aceptó el usuario o la contraseña: ningún documento llegó a salir. */
final class DigifactAuthException extends RuntimeException {}
