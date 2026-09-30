<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Modules\Social\Services\ZernioClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Trae, para cada empresa con Zernio conectado, lo publicado en Instagram/Facebook fuera del panel
 * (subido directo desde el celular). Sin esto el Historial de cada empresa solo enseña una parte de
 * lo que de verdad hay en su perfil, y solo se completa si alguien entra y pulsa «Sincronizar» a mano.
 *
 * Lo dispara el cron de Vercel (endpoint /tareas/sincronizar-redes) o el scheduler; también a mano:
 * `php artisan redes:sincronizar-publicaciones`.
 */
final class SyncExternalSocialPosts extends Command
{
    /** Solo estas admiten traer lo publicado fuera de Zernio; pedírselo a otra red no sirve de nada. */
    private const REDES_SINCRONIZABLES = ['instagram', 'facebook'];

    protected $signature = 'redes:sincronizar-publicaciones';

    protected $description = 'Trae, para cada empresa con redes conectadas, lo publicado fuera del panel (desde el celular).';

    public function handle(): int
    {
        // Sin scope de empresa: recorremos todas las que tienen la clave puesta.
        $companies = Company::query()
            ->whereNotNull('social_api_key')
            ->get();

        $empresas = 0;
        $encontradas = 0;

        foreach ($companies as $company) {
            // La clave puede seguir en la fila aunque el módulo se haya apagado después: respetar el
            // apagado evita gastar llamadas de una empresa que ya no usa esto.
            if (! $company->hasModule('social')) {
                continue;
            }

            $cliente = new ZernioClient($company);

            try {
                foreach ($cliente->accounts() as $cuenta) {
                    if (in_array($cuenta['platform'], self::REDES_SINCRONIZABLES, true) && ! $cuenta['necesita_reconectar']) {
                        $encontradas += $cliente->syncExternalPosts($cuenta['id']);
                    }
                }

                $empresas++;
            } catch (Throwable $e) {
                // Una empresa con la clave vencida o Zernio caído no puede tumbar la corrida de las
                // demás: se deja constancia y se sigue.
                report($e);
                $this->error("«{$company->name}» (#{$company->id}): {$e->getMessage()}");
            }
        }

        $this->info("Empresas sincronizadas: {$empresas}. Publicaciones nuevas encontradas: {$encontradas}.");

        return self::SUCCESS;
    }
}
