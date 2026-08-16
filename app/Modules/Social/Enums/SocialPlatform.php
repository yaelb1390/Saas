<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

/**
 * Las redes en las que se puede publicar.
 *
 * Los valores son los identificadores literales de Zernio y NO son decorativos: viajan tal cual en
 * cada publicación. Una errata aquí no daría error de compilación, solo un post que nunca sale.
 *
 * Se ofrecen las que un negocio local usa de verdad. Zernio admite catorce —discord, mastodon,
 * tumblr, snapchat…—; llenar la pantalla con redes que nadie de aquí va a conectar solo hace más
 * difícil encontrar Instagram. Añadir una es una línea.
 */
enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Twitter = 'twitter';
    case Threads = 'threads';
    case LinkedIn = 'linkedin';
    case Pinterest = 'pinterest';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube',
            self::Twitter => 'X (Twitter)',
            self::Threads => 'Threads',
            self::LinkedIn => 'LinkedIn',
            self::Pinterest => 'Pinterest',
        };
    }

    /**
     * ¿Exige foto o vídeo? Un texto suelto no se publica en estas.
     *
     * Es regla de la propia red, no de Zernio: Instagram, TikTok, YouTube y Pinterest son de imagen y
     * rechazan una publicación sin ella. Se sabe aquí para poder decirlo ANTES de intentarlo, en vez
     * de mandar el texto, esperar el rechazo y devolver un «no se pudo publicar» que no dice por qué.
     *
     * Comprobado contra la API: publicar «HOLA» en Instagram devuelve 400 «Instagram posts require
     * media content (images or videos)».
     */
    public function requiereMedia(): bool
    {
        return match ($this) {
            self::Instagram, self::TikTok, self::YouTube, self::Pinterest => true,
            self::Facebook, self::Twitter, self::Threads, self::LinkedIn => false,
        };
    }

    /** Color de marca, para reconocerla de un vistazo en la rejilla de cuentas. */
    public function color(): string
    {
        return match ($this) {
            self::Instagram => '#E1306C',
            self::Facebook => '#1877F2',
            self::TikTok => '#010101',
            self::YouTube => '#FF0000',
            self::Twitter => '#0F1419',
            self::Threads => '#000000',
            self::LinkedIn => '#0A66C2',
            self::Pinterest => '#BD081C',
        };
    }
}
