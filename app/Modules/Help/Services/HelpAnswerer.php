<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Models\User;
use App\Modules\AI\Providers\Contracts\AiProvider;
use App\Modules\Help\Models\AssistantQuestion;
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
        private readonly SmallTalk $smallTalk,
    ) {}

    /**
     * @param  list<array{pregunta: string, respuesta: string}>  $turnos  lo ya hablado, para poder repreguntar
     * @return array{
     *     question: string,
     *     answer: string|null,
     *     redactada: bool,
     *     article: HelpArticle|null,
     *     related: Collection<int, HelpArticle>,
     *     motivo: string|null,
     *     origen: string,
     * }
     */
    public function answer(string $pregunta, ?User $usuario = null, array $turnos = []): array
    {
        /*
         * Lo primero: ¿esto es una duda, o es «hola»?
         *
         * Va ANTES de buscar porque un saludo no se busca. Sin esto, «hola» contestaba «No encontré
         * nada sobre eso en el manual» —visto en pantalla— y «como estas» acababa en el artículo de
         * PRÉSTAMOS, porque «estas» se parecía a algo suyo. Las dos respuestas hacen que alguien
         * cierre la ventana en su primera frase, sin haber preguntado todavía.
         */
        $social = $this->smallTalk->responder($pregunta, $usuario);

        if ($social !== null) {
            return [
                'question' => $pregunta,
                'answer' => $social,
                'redactada' => true,
                'article' => null,
                'related' => collect(),
                'motivo' => null,
                'origen' => AssistantQuestion::SOCIAL,
            ];
        }

        $resultados = $this->search->search($pregunta, $usuario);
        $mejorVisible = $resultados->first()['score'] ?? 0;

        /*
         * ¿Lo que de verdad contesta esta pregunta es algo que este usuario no puede ver?
         *
         * Se compara por puntuación y no «si no hay resultados»: preguntar por las entregas puede
         * enganchar de refilón con el artículo de ventas y devolver algo, y contestar sobre ventas a
         * quien preguntó por entregas es peor que decirle la verdad.
         */
        $fuera = $this->search->fueraDeAlcance($pregunta, $usuario);

        if ($fuera !== null && $fuera['score'] > $mejorVisible) {
            return $this->fueraDeAlcance($pregunta, $fuera);
        }

        if ($resultados->isEmpty()) {
            return [
                'question' => $pregunta,
                'answer' => null,
                'redactada' => false,
                'article' => null,
                'related' => collect(),
                'motivo' => null,
                'origen' => AssistantQuestion::SIN_RESPUESTA,
            ];
        }

        /** @var HelpArticle $mejor */
        $mejor = $resultados->first()['article'];

        /** @var Collection<int, HelpArticle> $otros */
        $otros = $resultados->skip(1)->map(static fn (array $fila): HelpArticle => $fila['article'])->values();

        $redactada = $this->redactar($pregunta, $resultados, $turnos);

        return [
            'question' => $pregunta,
            'answer' => $redactada,
            'redactada' => $this->provider->redactaRespuestas(),
            'article' => $mejor,
            'related' => $otros,
            'motivo' => null,
            // Lo que PASÓ, no lo que se pretendía: si el proveedor falló, `redactar()` devuelve null
            // y lo que ve el cliente es el artículo. Registrar «ia» ahí escondería las caídas.
            'origen' => $redactada === null ? AssistantQuestion::CON_ARTICULO : AssistantQuestion::CON_IA,
        ];
    }

    /**
     * La respuesta cuando el artículo existe pero este usuario no puede verlo.
     *
     * NO pasa por la IA: es una frase fija. No hay nada que redactar, no puede alucinar, y no cuesta
     * dinero decirle a alguien que algo no está en su plan.
     *
     * Los dos motivos llevan respuestas opuestas a propósito:
     *
     *  - Le falta el MÓDULO → se le dice qué es y se le ofrece verlo. Es honesto y además es una
     *    venta que nace de una necesidad real, no de un anuncio.
     *  - Le falta el PERMISO → se le manda al dueño y NUNCA se le habla de planes. Su empresa ya lo
     *    tiene contratado; ofrecerle cambiar de plan a un cajero es mandarlo a molestar por una
     *    decisión que ni es suya ni hace falta.
     *
     * @param  array{article: HelpArticle, motivo: string, score: int}  $fuera
     * @return array{question: string, answer: string, redactada: bool, article: null, related: Collection<int, HelpArticle>, motivo: string, origen: string}
     */
    private function fueraDeAlcance(string $pregunta, array $fuera): array
    {
        $que = $fuera['article']->title;

        $respuesta = $fuera['motivo'] === 'modulo'
            ? "Eso es «{$que}», y no está incluido en el plan de tu empresa, por eso no te aparece en el menú. Si te interesa, el dueño puede verlo en los planes."
            : "Eso es «{$que}», y tu empresa sí lo tiene: lo que pasa es que tu usuario no tiene permiso para hacerlo. Pídeselo al dueño o a un administrador.";

        return [
            'question' => $pregunta,
            'answer' => $respuesta,
            // No la redactó nadie, pero es una respuesta de verdad: la pantalla debe enseñarla como
            // tal y no caer al modo «aquí tienes el artículo», que es justo lo que no puede leer.
            'redactada' => true,
            'article' => null,
            'related' => collect(),
            'motivo' => $fuera['motivo'],
            'origen' => $fuera['motivo'] === 'modulo'
                ? AssistantQuestion::FUERA_DE_PLAN
                : AssistantQuestion::SIN_PERMISO,
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
     * @param  list<array{pregunta: string, respuesta: string}>  $turnos
     */
    private function redactar(string $pregunta, Collection $resultados, array $turnos = []): ?string
    {
        if (! $this->provider->redactaRespuestas()) {
            return null;
        }

        $contexto = $resultados
            ->map(static fn (array $fila): string => "## {$fila['article']->title}\n{$fila['article']->body}")
            ->implode("\n\n---\n\n");

        $mensajes = [['role' => 'system', 'content' => $this->instrucciones($contexto)]];

        /*
         * Lo ya hablado, para que «¿y si ya la cobré?» signifique algo.
         *
         * Se manda como turnos de verdad y no pegado dentro de la pregunta: el modelo distingue quién
         * dijo qué, y sin esa distinción tiende a contestar otra vez lo anterior.
         *
         * Van SOLO los últimos —los recorta quien llama— porque cada turno se paga en cada pregunta
         * siguiente: sin tope, una conversación larga acaba costando más que el resto del día.
         */
        foreach ($turnos as $turno) {
            $mensajes[] = ['role' => 'user', 'content' => $turno['pregunta']];
            $mensajes[] = ['role' => 'assistant', 'content' => $turno['respuesta']];
        }

        $mensajes[] = ['role' => 'user', 'content' => $pregunta];

        try {
            return trim($this->provider->chat($mensajes));
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
