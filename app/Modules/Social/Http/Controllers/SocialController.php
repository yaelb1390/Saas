<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Enums\SocialPlatform;
use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Http\Requests\PublishPostRequest;
use App\Modules\Social\Services\ZernioClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Redes sociales: conectar las cuentas del negocio y publicar en todas a la vez.
 *
 * La pantalla NO guarda copia de nada: pregunta a Zernio las cuentas y las publicaciones cada vez.
 * Duplicarlas aquí crearía dos verdades que se separan en cuanto alguien publique desde el móvil o
 * borre una foto desde la propia app de Instagram, y entonces el panel mentiría sin que nadie
 * pudiera saber cuál de los dos tiene razón.
 *
 * Si el servicio no responde, la pantalla SE PINTA IGUAL con el aviso del motivo. Dejarla en blanco
 * —o peor, con un error 500— haría creer que se perdió lo publicado.
 */
final class SocialController extends Controller
{
    public function index(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $cliente = new ZernioClient($company);

        $cuentas = [];
        $publicaciones = [];
        $aviso = null;

        if ($cliente->isConfigured()) {
            try {
                $cuentas = $cliente->accounts();
                $publicaciones = $cliente->posts();
            } catch (SocialException $e) {
                $aviso = $e->getMessage();
            }
        }

        return view('panel.social', [
            'configurado' => $cliente->isConfigured(),
            'cuentas' => $cuentas,
            'publicaciones' => $publicaciones,
            'aviso' => $aviso,
            'plataformas' => SocialPlatform::cases(),
            // Qué redes no publican texto suelto, para avisar mientras se redacta. Sale de la
            // enumeración y no de una lista escrita a mano en el JavaScript: la regla la comprueba
            // también el servidor al publicar, y dos copias acabarían discrepando.
            'redesQueExigenFoto' => collect(SocialPlatform::cases())
                ->filter(static fn (SocialPlatform $r): bool => $r->requiereMedia())
                ->mapWithKeys(static fn (SocialPlatform $r): array => [$r->value => $r->label()])
                ->all(),
        ]);
    }

    /** Guarda (o borra) la clave de Zernio de la empresa. */
    public function saveKey(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $datos = $request->validate([
            // El formato lo publica Zernio: «sk_» y 64 hexadecimales. Comprobarlo aquí evita que un
            // dedazo se convierta en «no pudimos hablar con el servicio» diez minutos después.
            'api_key' => ['nullable', 'string', 'regex:/^sk_[0-9a-f]{64}$/'],
        ], [
            'api_key.regex' => 'Esa no parece una clave de Zernio: empiezan por «sk_» y siguen 64 caracteres.',
        ]);

        $company->update(['social_api_key' => $datos['api_key'] ?: null]);

        return back()->with('panel_ok', filled($datos['api_key'] ?? null)
            ? 'Clave guardada. Ya puedes conectar tus redes.'
            : 'Clave borrada. Tus redes dejan de estar conectadas al sistema.');
    }

    /** Manda al cliente a autorizar una red en la propia red. */
    public function connect(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $request->validate(['platform' => ['required', Rule::enum(SocialPlatform::class)]]);

        try {
            $url = (new ZernioClient($company))->connectUrl(
                SocialPlatform::from((string) $request->input('platform')),
            );
        } catch (SocialException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function publish(PublishPostRequest $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $datos = $request->validated();

        // Instagram y compañía no publican texto suelto. Se dice antes de llamar a nadie: el viaje
        // solo sirve para volver con un rechazo que el dueño no puede interpretar.
        $sinFoto = $request->redesQueExigenFoto();

        if ($sinFoto !== []) {
            $redes = implode(' y ', $sinFoto);

            return back()->withInput()->with('panel_error', count($sinFoto) === 1
                ? "{$redes} no publica solo texto: añade una foto o un vídeo."
                : "{$redes} no publican solo texto: añade una foto o un vídeo.");
        }

        try {
            (new ZernioClient($company))->publish(
                content: $datos['content'],
                targets: $request->objetivos(),
                scheduledFor: $datos['scheduled_for'] ?? null,
                media: $request->medios(),
            );
        } catch (SocialException $e) {
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', isset($datos['scheduled_for'])
            ? 'Publicación programada.'
            : 'Publicado en tus redes.');
    }

    /**
     * Permiso para que el NAVEGADOR suba la imagen directamente.
     *
     * El archivo no pasa por aquí: en producción el disco es de solo lectura y no hay librerías de
     * imagen, así que reenviarlo sería código que solo funciona en el portátil de quien lo escribió.
     */
    public function presign(Request $request, CurrentCompany $currentCompany): JsonResponse
    {
        $company = $currentCompany->model();
        abort_if($company === null, 403);

        $datos = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'in:image/jpeg,image/png,image/webp,video/mp4'],
        ]);

        try {
            return response()->json(
                (new ZernioClient($company))->presignMedia($datos['file_name'], $datos['content_type']),
            );
        } catch (SocialException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
