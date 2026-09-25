<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Controllers;

use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Services\CrmService;
use App\Modules\SocialCommerce\Models\Conversation;
use App\Modules\SocialCommerce\Models\OpportunityLink;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Las conversaciones de Instagram que el webhook ya fue guardando, y el puente hacia el CRM:
 * enlazar con un cliente existente (o crear uno) y abrir una oportunidad.
 *
 * Reutiliza `CrmService`/`Customer`/`Pipeline` tal cual, sin tocarlos (arquitectura, sección 1). La
 * atribución vive en `OpportunityLink`, no en una columna nueva de `opportunities`.
 */
final class ConversationController extends Controller
{
    public function index(): View
    {
        $conversations = Conversation::with(['contactIdentity.customer', 'rule.product'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->paginate(20);

        return view('panel.social-commerce.conversations.index', compact('conversations'));
    }

    public function show(Conversation $conversation): View
    {
        $conversation->load(['contactIdentity.customer', 'rule.product']);
        $conversation->setRelation('messages', $conversation->messages()->orderBy('sent_at')->get());

        return view('panel.social-commerce.conversations.show', [
            'conversation' => $conversation,
            'opportunityLink' => OpportunityLink::with('opportunity')->where('conversation_id', $conversation->id)->first(),
            // Una lista sencilla para enlazar a un cliente ya existente. Con pocas decenas de
            // clientes (el tamaño normal de un negocio pequeño) un desplegable basta; no hace
            // falta un buscador aparte para esto.
            'clientes' => Customer::query()->where('is_active', true)->orderBy('name')->limit(200)->get(['id', 'name', 'phone']),
        ]);
    }

    public function linkCustomer(Request $request, Conversation $conversation): RedirectResponse
    {
        $datos = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'nombre' => ['required_without:customer_id', 'nullable', 'string', 'max:150'],
        ]);

        $identidad = $conversation->contactIdentity;

        if (filled($datos['customer_id'] ?? null)) {
            // Customer::find() pasa por el global scope: un identificador de otra empresa no
            // aparece, así que esto también aísla por tenant sin una comprobación aparte.
            $cliente = Customer::find($datos['customer_id']);

            if ($cliente === null) {
                return back()->with('panel_error', 'Ese cliente no existe o no es de tu empresa.');
            }
        } else {
            $cliente = Customer::create([
                'name' => $datos['nombre'],
                'notes' => 'Creado desde una conversación de Instagram (Social Commerce).',
            ]);
        }

        $identidad->update(['customer_id' => $cliente->id]);

        return back()->with('panel_ok', "Enlazado con {$cliente->name}.");
    }

    public function createOpportunity(Conversation $conversation, CrmService $crm): RedirectResponse
    {
        if (OpportunityLink::where('conversation_id', $conversation->id)->exists()) {
            return back()->with('panel_error', 'Esta conversación ya tiene una oportunidad.');
        }

        if ($conversation->contactIdentity->customer === null) {
            return back()->with('panel_error', 'Enlaza primero un cliente: una oportunidad sin cliente no se puede dar seguimiento.');
        }

        $pipeline = Pipeline::query()->where('is_default', true)->first();

        if ($pipeline === null) {
            return back()->with('panel_error', 'No hay un pipeline configurado para crear la oportunidad.');
        }

        $producto = $conversation->rule?->product;
        $identidad = $conversation->contactIdentity;

        $opportunity = $crm->openOpportunity(
            pipeline: $pipeline,
            title: $producto?->name ?? "Instagram: {$identidad->display_name}",
            amount: $producto !== null ? (string) $producto->price : '0',
            customer: $identidad->customer,
        );

        OpportunityLink::create([
            'opportunity_id' => $opportunity->id,
            'conversation_id' => $conversation->id,
            'rule_id' => $conversation->rule_id,
        ]);

        return redirect()->route('panel.social-commerce.conversations.show', $conversation)
            ->with('panel_ok', 'Oportunidad creada.');
    }
}
