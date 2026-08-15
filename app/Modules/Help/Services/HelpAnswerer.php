<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Models\User;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\Help\Support\HelpArticle;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Contesta una pregunta sobre cómo se usa el sistema.
 *
 * Dos modos, y el primero es el que tiene que funcionar siempre:
 *
 *  - SIN clave de API: se devuelve el artículo del manual que responde. No es un premio de consuelo:
 *    el artículo está escrito para contestar esa pregunta, así que resuelve igual y no cuesta nada.
 *  - CON clave: la IA redacta una respuesta a la pregunta concreta usando esos artículos COMO ÚNICO
 *    contexto, y se sigue enseñando de cuál salió para que el cliente pueda comprobarlo.
 *
 * La regla que no se negocia: nunca se contesta con lo que el modelo crea saber. Si no hay artículo,
 * se dice que no se sabe. Un asistente que se inventa cómo funciona el sistema hace perder más tiempo
 * que no tener asistente, porque el cliente se fía.
 */
final class HelpAnswerer
{
    public function __construct(
        private readonly HelpSearch $search,
        private readonly AiProvider $provider,
    ) {}

    /**
     * @return array{
     *     question: string,
     *     answer: string|null,
     *     redactada: bool,
     *     article: HelpArticle|null,
     *     related: Collection<int, HelpArticle>,
     * }
     */
    public function answer(string $pregunta, ?User $usuario = null): array
    {
        $resultados = $this->search->search($pregunta, $usuario);

        if ($resultados->isEmpty()) {
            return [
                'question' => $pregunta,
                'answer' => null,
                'redactada' => false,
                'article' => null,
                'related' => collect(),
            ];
        }

        /** @var HelpArticle $mejor */
        $mejor = $resultados->first()['article'];

        /** @var Collection<int, HelpArticle> $otros */
        $otros = $resultados->skip(1)->map(static fn (array $fila): HelpArticle => $fila['article'])->values();

        return [
            'question' => $pregunta,
            'answer' => $this->redactar($pregunta, $resultados),
            'redactada' => $this->provider->redactaRespuestas(),
            'article' => $mejor,
            'related' => $otros,
        ];
    }

    /**
     * Redacción con IA, o null si no hay proveedor que redacte.
     *
     * Null NO es un fallo: la pantalla enseña el artículo entero, que es la respuesta. Por eso un
     * error del proveedor —sin red, clave caducada, cuota agotada— también devuelve null en vez de
     * reventar: la ayuda tiene que seguir funcionando el día que la API de turno esté caída.
     *
     * @param  Collection<int, array{article: HelpArticle, score: int}>  $resultados
     */
    private function redactar(string $pregunta, Collection $resultados): ?string
    {
        if (! $this->provider->redactaRespuestas()) {
            return null;
        }

        $contexto = $resultados
            ->map(static fn (array $fila): string => "## {$fila['article']->title}\n{$fila['article']->body}")
            ->implode("\n\n---\n\n");

        try {
            return trim($this->provider->chat([
                ['role' => 'system', 'content' => $this->instrucciones($contexto)],
                ['role' => 'user', 'content' => $pregunta],
            ]));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function instrucciones(string $contexto): string
    {
        return <<<TXT
        Eres el asistente de BM Business OS y contestas dudas sobre CÓMO SE USA el sistema.

        Reglas:
        - Responde ÚNICAMENTE con lo que diga el manual de abajo. Si no está ahí, di que no lo sabes
          y sugiere mirar el artículo más cercano. No supongas ni completes con lo que creas saber de
          otros sistemas: el cliente se va a fiar de lo que digas.
        - Sé breve y práctico: los pasos concretos que tiene que dar, en su orden.
        - Habla en español de República Dominicana, de tú, sin tecnicismos. Quien pregunta es el dueño
          de un colmado o su cajero, no un programador. Nada de «endpoint», «registro» ni «entidad».
        - Si el manual avisa de algo que no tiene vuelta atrás, DILO. Es lo más importante que puedes
          decirle a alguien que está a punto de tocar dinero o existencias.

        MANUAL:
        {$contexto}
        TXT;
    }
}
