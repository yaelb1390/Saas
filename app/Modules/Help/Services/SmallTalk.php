<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Models\User;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Support\Collection;

/**
 * Lo que se contesta cuando el usuario no está preguntando nada todavía.
 *
 * «Hola» no es una duda sobre el sistema, pero es lo primero que escribe media España y media
 * República Dominicana al abrir un chat. Contestarle «No encontré nada sobre eso en el manual» es
 * exactamente la respuesta que hace que alguien cierre la ventana y no vuelva: no ha hecho nada mal
 * y ya le han dicho que no.
 *
 * Esto NO pasa por la IA, y es a propósito:
 *
 *  · No hay nada que redactar. Un saludo tiene una buena respuesta y siempre es la misma.
 *  · No puede inventarse nada, que es el riesgo de dejar al modelo suelto en conversación abierta.
 *  · No cuesta dinero. Serían las preguntas más frecuentes y las más inútiles de pagar.
 *
 * Y sobre todo: la respuesta DEVUELVE AL TEMA. No se entra a charlar. Se saluda y se dice para qué
 * sirve, que es lo que el usuario necesita saber en ese momento.
 */
final class SmallTalk
{
    /**
     * Saludos y cortesías. La respuesta saluda y reconduce.
     *
     * @var list<string>
     */
    private const SALUDOS = [
        'hola', 'holi', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches', 'saludos',
        'que tal', 'que lo que', 'klk', 'k lo k', 'hey', 'ola', 'buen dia',
    ];

    /** @var list<string> */
    private const AGRADECIMIENTOS = [
        'gracias', 'muchas gracias', 'mil gracias', 'te lo agradezco', 'ok gracias', 'listo gracias',
        'perfecto', 'excelente', 'genial', 'de lujo', 'ya esta', 'entendido', 'entiendo', 'vale',
    ];

    /** @var list<string> */
    private const DESPEDIDAS = [
        'adios', 'chao', 'chau', 'hasta luego', 'nos vemos', 'bye', 'me voy',
    ];

    /** Preguntas por el asistente en sí. Se contestan diciendo para qué sirve. */
    private const SOBRE_MI = [
        'como estas', 'quien eres', 'que eres', 'como te llamas', 'eres un robot', 'eres humano',
        'eres una ia', 'que puedes hacer', 'en que me puedes ayudar', 'para que sirves',
        'que sabes', 'ayuda', 'ayudame', 'necesito ayuda',
    ];

    /**
     * La respuesta, o null si esto era una pregunta de verdad y hay que buscarla en el manual.
     */
    public function responder(string $texto, ?User $usuario = null): ?string
    {
        $limpio = $this->normalizar($texto);

        if ($limpio === '') {
            return null;
        }

        /*
         * La comprobación es de IGUALDAD, no de «contiene», y ahí está todo el asunto.
         *
         * «Hola, ¿cómo anulo una venta?» lleva un saludo dentro y NO es un saludo: es una pregunta de
         * verdad que hay que buscar. Si esto usara `str_contains`, esa pregunta —y cualquiera que
         * empiece educadamente— se comería un «¡Hola! ¿En qué te ayudo?» y el usuario tendría que
         * repetirla sin los buenos días.
         */
        if ($this->esSolo($limpio, self::SALUDOS)) {
            return '¡Hola! Soy el asistente de BM Business y te explico cómo se usa el sistema. '
                .'Pregúntame lo que necesites hacer, por ejemplo «¿cómo anulo una venta?» o '
                .'«¿cómo cierro la caja?».';
        }

        if ($this->esSolo($limpio, self::AGRADECIMIENTOS)) {
            return '¡A ti! Si te surge otra duda con el sistema, aquí estoy.';
        }

        if ($this->esSolo($limpio, self::DESPEDIDAS)) {
            return '¡Hasta luego! Cualquier duda con el sistema, me escribes.';
        }

        if ($this->esSolo($limpio, self::SOBRE_MI)) {
            return $this->quienSoy($usuario);
        }

        return null;
    }

    /**
     * Qué sé hacer, contado con los módulos que ESA empresa tiene.
     *
     * No es una lista genérica: ofrecerle ayuda con las Entregas a quien no las contrató es la misma
     * mentira que explicárselas. Se enseñan cuatro y no los quince porque es una respuesta de chat,
     * no un catálogo.
     */
    private function quienSoy(?User $usuario): string
    {
        $empresa = $usuario?->company;

        $suyos = $empresa === null
            ? Collection::make(ModuleRegistry::all())->keys()
            : Collection::make($empresa->activeModules());

        $nombres = $suyos
            ->map(static fn (string $clave): string => ModuleRegistry::label($clave))
            ->filter()
            ->take(4)
            ->implode(', ');

        return 'Soy el asistente de BM Business. No soy una persona: te explico cómo se usa el '
            .'sistema, paso a paso y solo con lo que dice el manual, sin inventarme nada.'
            .($nombres === '' ? '' : " Puedo ayudarte con {$nombres} y lo demás que tengas contratado.")
            .' Dime qué quieres hacer y te digo dónde se hace.';
    }

    /**
     * ¿El mensaje es SOLO una de estas frases, sin nada más?
     *
     * @param  list<string>  $frases
     */
    private function esSolo(string $limpio, array $frases): bool
    {
        foreach ($frases as $frase) {
            if ($limpio === $this->normalizar($frase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas, sin acentos, sin signos y sin los rellenos con los que se escribe en un chat.
     *
     * Se quitan «buenas!!», «hola." y «ok, gracias» para que las tres caigan en la misma frase: quien
     * escribe en un chat no cuida la puntuación, y una lista que solo case con la forma limpia no
     * acierta casi nunca.
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        $texto = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto) ?? $texto;

        // Rellenos que no cambian la intención: «ok gracias» y «gracias ok» son lo mismo.
        $texto = preg_replace('/\b(ok|okay|oki|dale|pues|bueno|este|eh)\b/u', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
    }
}
