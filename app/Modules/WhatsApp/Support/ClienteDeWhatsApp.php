<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Support;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\WhatsApp\Models\WaConversation;
use Throwable;

/**
 * Dar de alta en el CRM a quien escribe por WhatsApp.
 *
 * Antes la conversación se enlazaba con un cliente SOLO si ya existía uno con ese teléfono. Quien
 * escribía por primera vez dejaba una conversación huérfana: aparecía en la bandeja, se le podía
 * contestar, y sin embargo no había ficha con la que cotizarle, ni historial, ni nada que mirar la
 * próxima vez que llamara. Ese hueco entre «me habló un cliente» y «tengo un cliente» era todo el
 * problema.
 */
final class ClienteDeWhatsApp
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Crea la ficha si hace falta y la engancha a la conversación.
     *
     * NUNCA lanza. Un tropiezo aquí no puede costar el mensaje del cliente, que es lo que de verdad
     * importa y ya está guardado cuando esto corre.
     */
    public function deLaConversacion(WaConversation $conversation, ?string $phone, ?string $name): ?Customer
    {
        try {
            return $this->intentar($conversation, $phone, $name);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function intentar(WaConversation $conversation, ?string $phone, ?string $name): ?Customer
    {
        $telefono = trim((string) $phone);

        /*
         * Sin teléfono de verdad no se crea nada.
         *
         * Por la vía oficial de Meta el remitente puede llegar como BSUID —un identificador interno,
         * no un número—, y una ficha de cliente con eso en el campo del teléfono no sirve para
         * llamar a nadie: solo ensucia el CRM con contactos imposibles de usar.
         */
        if ($telefono === '' || ! $this->pareceTelefono($telefono)) {
            return null;
        }

        $empresa = $this->currentCompany->model();

        /*
         * Y solo si la empresa contrató el CRM.
         *
         * Llenarle una tabla que no puede abrir es guardarle datos personales de sus clientes sin
         * darle forma de verlos ni de borrarlos.
         */
        if ($empresa !== null && ! $empresa->hasModule('crm')) {
            return null;
        }

        $existente = Customer::query()->where('phone', $telefono)->first();

        if ($existente !== null) {
            // Ya estaba: solo se ata la conversación, sin tocar su ficha. El nombre que manda
            // WhatsApp es el que se puso el cliente, y no tiene por qué mandar sobre el que el
            // negocio escribió a mano.
            $this->enlazar($conversation, $existente);

            return $existente;
        }

        $cliente = Customer::create([
            'company_id' => $conversation->company_id,
            // El nombre de WhatsApp, o el propio número si no manda ninguno: una ficha «Sin nombre»
            // no se distingue de las otras diez, y el número al menos identifica a alguien.
            'name' => filled($name) ? $name : $telefono,
            'phone' => $telefono,
            'is_active' => true,
        ]);

        $this->enlazar($conversation, $cliente);

        return $cliente;
    }

    private function enlazar(WaConversation $conversation, Customer $cliente): void
    {
        if ($conversation->customer_id === null) {
            $conversation->update(['customer_id' => $cliente->id]);
        }
    }

    /**
     * ¿Esto es un número de teléfono?
     *
     * Se pide que sean solo dígitos y que haya al menos ocho: un BSUID lleva letras, y ocho es lo
     * mínimo de un número dominicano sin prefijo. No valida el país a propósito —aquí escribe gente
     * de fuera— sino que descarta lo que claramente no es un teléfono.
     */
    private function pareceTelefono(string $valor): bool
    {
        return preg_match('/^\d{8,20}$/', $valor) === 1;
    }
}
