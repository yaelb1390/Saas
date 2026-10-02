<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Models\User;
use App\Modules\Help\Models\AssistantQuestion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * El asistente de la burbuja: lo que hace falta para que sea una CONVERSACIÓN y no una búsqueda.
 *
 * `HelpAnswerer` ya sabía contestar una pregunta suelta. Aquí se le añade lo que le faltaba para
 * seguir un hilo:
 *
 *  · recordar lo ya hablado, para que «¿y si ya la cobré?» signifique algo;
 *  · dejar constancia de lo que se preguntó, que es de donde sale el tope diario y —más importante—
 *    la lista de lo que el manual no cubre.
 */
final class AssistantAnswerer
{
    /**
     * Cuántos turnos anteriores viajan al proveedor.
     *
     * Tres, y no «toda la conversación», porque el historial se manda ENTERO en cada pregunta: con
     * veinte turnos, la vigésimo primera pregunta paga veinte veces. Tres bastan para entender un
     * «¿y entonces?» y ahí se corta.
     */
    private const TURNOS_RECORDADOS = 3;

    /**
     * Cuánto sobrevive el hilo sin preguntas nuevas.
     *
     * Una conversación de ayuda es corta por naturaleza; media hora de silencio ya es otra sesión de
     * trabajo, no una pausa a media pregunta.
     */
    private const MINUTOS_VIVO = 30;

    public function __construct(private readonly HelpAnswerer $answerer) {}

    /**
     * La clave del hilo en caché, por usuario y por empresa.
     *
     * No va en la sesión HTTP a propósito: con la sesión en cookie (sin Redis en producción, y la
     * alternativa —sesión en la base de datos— paga un viaje a la BD en CADA petición del panel, no
     * solo al preguntarle algo al asistente), un hilo de tres turnos con hasta 1500 caracteres cada uno
     * se sale del límite de una cookie. El historial de la conversación es estado efímero, no identidad
     * de sesión, así que vive en caché —que en producción también es la base de datos, pero se lee una
     * vez por pregunta, no en cada clic—.
     *
     * Por usuario y no por ID de sesión: el ID de sesión se regenera al iniciar sesión (y en los tests,
     * entre peticiones separadas), así que un hilo indexado por sesión se perdería solo. Lleva la
     * empresa porque el operador de la plataforma cambia de una a otra sin cerrar sesión y, sin esto, se
     * llevaría el hilo de una empresa a la siguiente y el asistente contestaría con contexto ajeno.
     */
    public static function claveDeHilo(int $companyId): string
    {
        return 'asistente.hilo.'.(auth()->id() ?? 'anon').'.'.$companyId;
    }

    /**
     * @param  int  $companyId  la empresa ACTIVA, no la del usuario.
     *
     * No son lo mismo y confundirlas rompe: el operador de la plataforma entra al panel de una
     * empresa cliente conservando su propio `company_id` —o ninguno—, así que tomarlo del usuario
     * apuntaba a una empresa que no existe y la pregunta moría con una violación de clave foránea.
     * Vino de verdad, probando en el punto de venta.
     * @return array{respuesta: string|null, articulo: array{titulo: string, url: string}|null, motivo: string|null, agotada: bool}
     */
    public function responder(string $pregunta, ?User $usuario, int $companyId): array
    {
        $clave = self::claveDeHilo($companyId);

        /** @var list<array{pregunta: string, respuesta: string}> $hilo */
        $hilo = Cache::get($clave, []);

        $resultado = $this->answerer->answer($pregunta, $usuario, $hilo);

        AssistantQuestion::create([
            'company_id' => $companyId,
            'user_id' => $usuario?->id,
            // Recortada al tamaño de la columna: una pregunta enorme no puede tumbar la respuesta.
            'question' => Str::limit($pregunta, 490, ''),
            'answered_by' => $resultado['origen'],
            'article_slug' => $resultado['article']?->slug,
        ]);

        /*
         * Lo que se le enseña al usuario, que es lo que hay que recordar.
         *
         * Cuando el proveedor no redacta, la respuesta ES el artículo; guardarlo en el hilo como si
         * fuera texto del asistente permite que la siguiente pregunta tenga contexto igualmente.
         */
        $texto = $resultado['answer'] ?? $resultado['article']?->body;

        if ($texto !== null) {
            $hilo[] = ['pregunta' => $pregunta, 'respuesta' => Str::limit($texto, 1500, '')];
            Cache::put($clave, array_slice($hilo, -self::TURNOS_RECORDADOS), now()->addMinutes(self::MINUTOS_VIVO));
        }

        return [
            'respuesta' => $resultado['answer'],
            'articulo' => $resultado['article'] === null ? null : [
                'titulo' => $resultado['article']->title,
                'url' => route('panel.help.article', $resultado['article']->slug),
            ],
            'motivo' => $resultado['motivo'],
            'agotada' => false,
        ];
    }
}
