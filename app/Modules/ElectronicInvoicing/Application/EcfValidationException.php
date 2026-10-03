<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\ElectronicInvoicing\Xml\ValidationError;
use RuntimeException;

/** El documento no se puede emitir: lleva los errores en español para enseñarlos tal cual. */
final class EcfValidationException extends RuntimeException
{
    /** @param  list<ValidationError>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', array_map(fn (ValidationError $e): string => $e->message, $errors)));
    }
}
