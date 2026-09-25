<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Enums;

/** Por dónde sale el texto: bajo el comentario (público) o por mensaje privado. */
enum TemplateChannel: string
{
    case Dm = 'dm';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Dm => 'Mensaje privado',
            self::Public => 'Respuesta pública',
        };
    }
}
