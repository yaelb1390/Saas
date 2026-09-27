<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use App\Modules\Social\Enums\SocialPlatform;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que se manda a publicar.
 *
 * Las cuentas llegan como «plataforma|id» en una sola casilla porque Zernio necesita las dos cosas
 * juntas y el navegador no tiene por qué saber emparejarlas: se parte aquí, en el servidor, y una
 * plataforma que no reconozcamos se descarta en vez de viajar tal cual.
 */
final class PublishPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000'],
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => ['string'],
            // Programar en el pasado publicaría al instante sin avisar, que no es lo que nadie
            // quiere de un botón que dice «programar».
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'media_url' => ['nullable', 'url', 'max:2000'],
            'media_type' => ['nullable', 'in:image,video'],
            // Límite y formato de Zernio: hasta 3 usuarios públicos de Business/Creator, sin el
            // «@» (se acepta con o sin él y se limpia en objetivos()). Solo aplica a Instagram, y
            // solo al crear la publicación — Zernio no lo admite agregado después.
            'collaborators' => ['nullable', 'array', 'max:3'],
            'collaborators.*' => ['string', 'max:30', 'regex:/^@?[a-zA-Z0-9._]+$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'content' => 'texto', 'accounts' => 'cuentas',
            'scheduled_for' => 'fecha de publicación', 'collaborators' => 'colaboradores',
        ];
    }

    public function messages(): array
    {
        return [
            'accounts.required' => 'Elige al menos una red donde publicar.',
            'scheduled_for.after' => 'Esa fecha ya pasó. Elige una futura o publica ahora.',
            'collaborators.max' => 'Instagram admite hasta 3 colaboradores por publicación.',
            'collaborators.*.regex' => 'Ese usuario de Instagram no parece válido.',
        ];
    }

    /**
     * Las cuentas elegidas, en la forma que espera Zernio.
     *
     * @return array<int, array{platform: string, accountId: string, platformSpecificData?: array{collaborators: array<int, string>}}>
     */
    public function objetivos(): array
    {
        $objetivos = [];
        $colaboradores = $this->colaboradores();

        foreach ((array) $this->input('accounts', []) as $valor) {
            [$plataforma, $id] = array_pad(explode('|', (string) $valor, 2), 2, null);

            // Una plataforma desconocida se descarta en silencio: mandarla haría fallar la
            // publicación ENTERA, incluidas las cuentas que sí valían.
            if (SocialPlatform::tryFrom((string) $plataforma) === null || blank($id)) {
                continue;
            }

            $objetivo = ['platform' => (string) $plataforma, 'accountId' => (string) $id];

            // Zernio solo admite colaboradores en Instagram (ni Historias): mandarlo con otra red
            // no haría nada útil y arriesgaría un 400 que tumbe TODA la publicación.
            if ($plataforma === SocialPlatform::Instagram->value && $colaboradores !== []) {
                $objetivo['platformSpecificData'] = ['collaborators' => $colaboradores];
            }

            $objetivos[] = $objetivo;
        }

        return $objetivos;
    }

    /**
     * Los usuarios a invitar como colaboradores, sin el «@» y sin duplicados.
     *
     * @return array<int, string>
     */
    public function colaboradores(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $u): string => ltrim(trim((string) $u), '@'),
            (array) $this->input('collaborators', []),
        ), static fn (string $u): bool => $u !== '')));
    }

    /**
     * Las redes elegidas que no publican sin foto, cuando no se ha adjuntado ninguna.
     *
     * Se comprueba aquí y no solo en el navegador porque es la regla de verdad: Instagram rechaza el
     * texto suelto con un 400, y sin esto el dueño escribía su oferta, pulsaba «Publicar ahora» y
     * recibía un «no se pudo publicar» sin saber que le faltaba la foto.
     *
     * @return array<int, string> Nombres de las redes, para nombrarlas en el aviso
     */
    public function redesQueExigenFoto(): array
    {
        if (filled($this->input('media_url'))) {
            return [];
        }

        $faltan = [];

        foreach ($this->objetivos() as $objetivo) {
            $red = SocialPlatform::from($objetivo['platform']);

            if ($red->requiereMedia()) {
                $faltan[$red->value] = $red->label();
            }
        }

        return array_values($faltan);
    }

    /**
     * @return array<int, array{type: string, url: string}>
     */
    public function medios(): array
    {
        if (blank($this->input('media_url'))) {
            return [];
        }

        return [[
            'type' => (string) $this->input('media_type', 'image'),
            'url' => (string) $this->input('media_url'),
        ]];
    }
}
