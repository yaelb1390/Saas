<?php

declare(strict_types=1);

namespace App\Modules\Help\Services;

use App\Models\User;
use App\Modules\Core\Support\ChatText;
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
        // Normalizar y comparar viven en ChatText: el bot de WhatsApp hace exactamente lo mismo con
        // los mensajes de los clientes, y dos copias del mismo criterio acaban divergiendo.
        if (ChatText::esAlgunaDe($texto, self::SALUDOS)) {
            return '¡Hola! Soy el asistente de BM Business y te explico cómo se usa el sistema. '
                .'Pregúntame lo que necesites hacer, por ejemplo «¿cómo anulo una venta?» o '
                .'«¿cómo cierro la caja?».';
        }

        if (ChatText::esAlgunaDe($texto, self::AGRADECIMIENTOS)) {
            return '¡A ti! Si te surge otra duda con el sistema, aquí estoy.';
        }

        if (ChatText::esAlgunaDe($texto, self::DESPEDIDAS)) {
            return '¡Hasta luego! Cualquier duda con el sistema, me escribes.';
        }

        if (ChatText::esAlgunaDe($texto, self::SOBRE_MI)) {
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
}
