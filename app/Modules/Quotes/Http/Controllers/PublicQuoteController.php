<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Http\Controllers;

use App\Modules\Quotes\Models\Quote;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * La cotización tal como la ve el cliente. Sin sesión: el cliente no es usuario del sistema.
 *
 * Quien autentica es LA FIRMA DE LA URL. El middleware `signed` comprueba el HMAC y la caducidad
 * antes de llegar aquí, así que un id cambiado a mano no abre la cotización de otra persona. Y el
 * enlace caduca porque un chat se reenvía: una dirección eterna sería una puerta abierta a lo que se
 * le ofertó a alguien más.
 *
 * El modelo se busca SIN el scope de empresa —a propósito— porque aquí no hay empresa activa: no hay
 * sesión de la que sacarla. La cotización trae la suya y de ella salen los datos del negocio.
 */
final class PublicQuoteController extends Controller
{
    public function __invoke(int $quote): View
    {
        return view('quotes.public', [
            'quote' => $this->buscar($quote),
        ]);
    }

    /** El mismo documento, en PDF. Es también la dirección que se le pasa a WhatsApp. */
    public function pdf(int $quote): Response
    {
        return QuoteController::construirPdf($this->buscar($quote));
    }

    private function buscar(int $id): Quote
    {
        $quote = Quote::withoutCompanyScope()
            ->with(['items', 'company'])
            ->find($id);

        abort_if($quote === null, 404);

        return $quote;
    }
}
