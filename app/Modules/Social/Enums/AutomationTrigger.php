<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

/**
 * Qué dispara una respuesta automática.
 *
 * Los valores son los de Zernio y viajan tal cual.
 *
 * Las HISTORIAS importan más de lo que parece. Instagram no deja escribirle a alguien porque sí: la
 * ventana para mandarle un privado la abre ÉL, y responder una historia es una de las tres formas de
 * abrirla. Es además donde interactúa la gente que acaba de empezar a seguirte, que es justo a quien
 * más interesa contestar.
 */
enum AutomationTrigger: string
{
    case Comment = 'comment';
    case StoryReply = 'story_reply';

    /** Lo normal: la mayoría de las automatizaciones cuelgan de una publicación. */
    public const POR_OMISION = self::Comment;

    public function label(): string
    {
        return match ($this) {
            self::Comment => 'Cuando comenten una publicación',
            self::StoryReply => 'Cuando respondan una historia',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Comment => 'La respuesta sale bajo el comentario y por privado.',
            self::StoryReply => 'Solo en Instagram. También contesta si te escriben esa palabra por privado.',
        };
    }

    /** ¿Se puede colgar de una publicación concreta? Las historias, no: valen para todas. */
    public function admitePublicacion(): bool
    {
        return $this === self::Comment;
    }

    /**
     * ¿Admite «responder también por privado»?
     *
     * En las historias NO, y lo rechaza la propia API: «alsoMatchInDms is not available on
     * story_reply automations (they already trigger on DMs)» —comprobado contra su servidor, no
     * leído—. O sea que no es que falte: es que ya lo hace siempre.
     */
    public function admiteRespuestaEnPrivados(): bool
    {
        return $this === self::Comment;
    }
}
