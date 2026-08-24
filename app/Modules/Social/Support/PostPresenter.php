<?php

declare(strict_types=1);

namespace App\Modules\Social\Support;

use App\Modules\Social\Enums\SocialPlatform;
use Illuminate\Support\Carbon;

/**
 * Convierte el volcado de Zernio en lo que la pantalla necesita enseñar.
 *
 * La API devuelve el objeto entero —veintiún campos por publicación, más los de cada destino— y
 * hasta ahora la vista pintaba tres: el texto recortado, una etiqueta de estado y la hora. El resto
 * estaba ahí y no se veía, incluidas dos cosas que el dueño de un negocio sí necesita:
 *
 * 1. **LA FOTO.** Lo que se publica en Instagram es la imagen; el texto es el pie. Una lista de
 *    publicaciones sin imágenes es una lista de pies de foto, y no hay forma de reconocer cuál es
 *    cuál si todas empiezan igual —que es justo lo que pasa cuando las escribe una IA—.
 *
 * 2. **EL ESTADO DE CADA DESTINO.** Una publicación que salió en Instagram y falló en Facebook
 *    llevaba UNA sola etiqueta. Decía «publicado a medias» sin decir cuál de las dos mitades, así
 *    que había que ir a mirar a las dos redes.
 *
 * Va aparte del controlador porque son decisiones de presentación con reglas propias —qué es
 * cancelable, qué hora se enseña— y aquí se pueden probar sin levantar una petición ni falsear la
 * API entera.
 */
final class PostPresenter
{
    /**
     * @param  array<int, array<string, mixed>>  $publicaciones  tal como llegan de la API
     * @param  array<int, array<string, mixed>>  $cuentas  las de la empresa, para poner nombres
     * @return array<int, array<string, mixed>>
     */
    public static function paraPantalla(array $publicaciones, array $cuentas): array
    {
        // id de cuenta → nombre. Sin esto, un destino se enseñaría como «instagram» a secas, y quien
        // tiene dos cuentas de Instagram no sabría en cuál salió.
        $nombres = [];

        foreach ($cuentas as $cuenta) {
            $nombres[(string) ($cuenta['id'] ?? '')] = (string) ($cuenta['name'] ?? '');
        }

        return array_map(
            static fn (array $post): array => self::una($post, $nombres),
            array_values($publicaciones),
        );
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array<string, string>  $nombres
     * @return array<string, mixed>
     */
    private static function una(array $post, array $nombres): array
    {
        $estado = (string) ($post['status'] ?? '');
        $destinos = self::destinos((array) ($post['platforms'] ?? []), $nombres);

        return [
            'id' => (string) ($post['_id'] ?? ''),
            'texto' => (string) ($post['content'] ?? ''),
            'estado' => $estado,
            'etiqueta' => self::etiqueta($estado),
            'tono' => self::tono($estado),
            'destinos' => $destinos,

            ...self::media((array) ($post['mediaItems'] ?? [])),

            'creado' => self::hora($post['createdAt'] ?? null),
            'sale_el' => self::hora($post['scheduledFor'] ?? null),

            /*
             * Cuándo salió de verdad: la del destino, no la del conjunto.
             *
             * Una publicación programada para las 8 puede salir a las 8:03 porque la red tardó en
             * aceptarla, y es esa la hora que vio la gente.
             */
            'publicado' => collect($destinos)->pluck('publicado')->filter()->first(),

            /*
             * Solo se cancela lo que todavía no ha salido.
             *
             * Lo publicado exige despublicarlo, que es otra cosa: ya lo vio gente, y puede que
             * alguien lo haya compartido. Ofrecer «cancelar» ahí prometería algo que no pasa.
             */
            'se_puede_cancelar' => in_array($estado, ['scheduled', 'draft'], true)
                && filled($post['_id'] ?? null),
        ];
    }

    /**
     * Cada destino con SU estado, SU enlace y SU hora.
     *
     * @param  array<int, mixed>  $platforms
     * @param  array<string, string>  $nombres
     * @return array<int, array<string, mixed>>
     */
    private static function destinos(array $platforms, array $nombres): array
    {
        $salida = [];

        foreach ($platforms as $destino) {
            $destino = (array) $destino;
            $red = SocialPlatform::tryFrom((string) ($destino['platform'] ?? ''));

            // El id puede venir suelto o dentro de un objeto, según cómo lo devuelva la API.
            $cuentaId = is_array($destino['accountId'] ?? null)
                ? (string) ($destino['accountId']['_id'] ?? '')
                : (string) ($destino['accountId'] ?? '');

            $estado = (string) ($destino['status'] ?? '');

            $salida[] = [
                'red' => $red?->label() ?? (string) ($destino['platform'] ?? '—'),
                'color' => $red?->color() ?? '#94a3b8',
                'cuenta' => $nombres[$cuentaId] ?? null,
                'estado' => $estado,
                'etiqueta' => self::etiqueta($estado),
                'url' => filled($destino['platformPostUrl'] ?? null)
                    ? (string) $destino['platformPostUrl']
                    : null,
                'publicado' => self::hora($destino['publishedAt'] ?? null),
                /*
                 * Cuántas veces hubo que intentarlo.
                 *
                 * Se enseña solo cuando pasa de uno. Que una publicación necesitara tres intentos no
                 * es un detalle técnico: es el aviso de que esa cuenta está dando guerra, y se ve
                 * antes de que un día el tercer intento tampoco funcione.
                 */
                'intentos' => (int) ($destino['publishAttempts'] ?? 0),
                'motivo' => self::motivo($destino),
            ];
        }

        return $salida;
    }

    /**
     * Por qué falló, si la API lo cuenta.
     *
     * Se buscan varias claves porque la respuesta no es uniforme: un fallo de la red viene en un
     * sitio y uno de validación en otro. Sin esto, «Falló» es una etiqueta sin ninguna pista de qué
     * hacer a continuación.
     *
     * @param  array<string, mixed>  $destino
     */
    private static function motivo(array $destino): ?string
    {
        foreach (['errorMessage', 'error', 'failureReason'] as $clave) {
            $valor = $destino[$clave] ?? null;

            if (is_string($valor) && trim($valor) !== '') {
                return trim($valor);
            }

            if (is_array($valor) && is_string($valor['message'] ?? null)) {
                return trim($valor['message']);
            }
        }

        return null;
    }

    /**
     * La foto o el vídeo, si lleva.
     *
     * @param  array<int, mixed>  $mediaItems
     * @return array{foto: string|null, es_video: bool, medias: int}
     */
    private static function media(array $mediaItems): array
    {
        $primera = (array) ($mediaItems[0] ?? []);
        $tipo = (string) ($primera['type'] ?? '');

        return [
            'foto' => filled($primera['url'] ?? null) ? (string) $primera['url'] : null,
            'es_video' => str_contains($tipo, 'video'),
            // Cuántas van en total: un carrusel de cinco fotos se enseña como una sola miniatura, y
            // conviene decir que hay cuatro más detrás.
            'medias' => count($mediaItems),
        ];
    }

    /** En la zona del negocio, no en UTC: aquí son cuatro horas menos que lo que manda la API. */
    private static function hora(mixed $valor): ?Carbon
    {
        if (blank($valor) || ! is_string($valor)) {
            return null;
        }

        return rescue(
            fn (): Carbon => Carbon::parse($valor)->timezone(config('app.business_timezone')),
            null,
            report: false,
        );
    }

    private static function etiqueta(string $estado): string
    {
        return match ($estado) {
            'published' => 'Publicado',
            'scheduled' => 'Programado',
            'publishing' => 'Publicando',
            'pending' => 'En espera',
            'failed' => 'Falló',
            'partial' => 'Publicado a medias',
            'draft' => 'Borrador',
            'cancelled' => 'Cancelado',
            default => $estado !== '' ? $estado : '—',
        };
    }

    private static function tono(string $estado): string
    {
        return match ($estado) {
            'published' => 'badge-green',
            'scheduled' => 'badge-blue',
            'publishing', 'partial', 'pending' => 'badge-amber',
            'failed' => 'badge-red',
            default => 'badge-gray',
        };
    }
}
