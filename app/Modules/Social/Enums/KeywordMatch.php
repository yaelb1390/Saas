<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

/**
 * Cómo se compara una palabra clave con lo que escribió el seguidor.
 *
 * Los valores son los de Zernio y viajan tal cual.
 *
 * OJO CON `contains`, QUE ES EL VALOR POR OMISIÓN DE LA API: compara en cualquier parte, incluso
 * dentro de otra palabra. Su propia especificación lo advierte con «app» disparando en «happy»; en
 * español es peor, porque «precio» dispara con «aprecio» y «comenta» con «comentario».
 *
 * Por eso aquí el valor por omisión es `word`: la palabra suelta. Una respuesta automática habla con
 * clientes reales sin nadie delante, y equivocarse de destinatario cientos de veces antes de que
 * alguien lo note es peor que no responder a uno.
 */
enum KeywordMatch: string
{
    case Word = 'word';
    case Exact = 'exact';
    case Contains = 'contains';

    /** Lo que se ofrece cuando nadie elige. Deliberadamente NO es el de la API. */
    public const POR_OMISION = self::Word;

    public function label(): string
    {
        return match ($this) {
            self::Word => 'La palabra suelta',
            self::Exact => 'El comentario exacto',
            self::Contains => 'En cualquier parte',
        };
    }

    /** Se explica con un ejemplo porque la diferencia solo se entiende viéndola fallar. */
    public function hint(): string
    {
        return match ($this) {
            self::Word => 'Responde a «precio» y a «el precio?», pero no a «aprecio». Lo normal.',
            self::Exact => 'Solo si el comentario es exactamente la palabra y nada más.',
            self::Contains => 'Responde también dentro de otra palabra: «precio» saltaría con «aprecio».',
        };
    }
}
