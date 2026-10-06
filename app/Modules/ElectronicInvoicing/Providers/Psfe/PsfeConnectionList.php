<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

/**
 * Las conexiones de una empresa con proveedores certificados, en su orden: la primera es la
 * PRINCIPAL y las siguientes, los respaldos que se usan si la anterior falla.
 *
 * Se guardan en `provider_config` (cifrado, fuera de la auditoría) como
 * `{ connections: [ {psfe, credentials, account, connected_at, checked_at, check_ok, check_message}, … ] }`.
 * El orden de la lista ES la prioridad: no hay un número aparte que pueda quedar repetido o con huecos.
 *
 * La forma anterior —una sola conexión suelta, `{psfe, credentials, …}`— se lee como una lista de un
 * elemento: nadie pierde la conexión que ya tenía, y al guardar queda en la forma nueva.
 */
final class PsfeConnectionList
{
    /**
     * @param  array<string, mixed>|null  $config
     * @return list<array<string, mixed>>
     */
    public static function read(?array $config): array
    {
        $config ??= [];

        if (isset($config['connections']) && is_array($config['connections'])) {
            return array_values(array_filter($config['connections'], fn ($c): bool => is_array($c) && filled($c['psfe'] ?? null)));
        }

        return filled($config['psfe'] ?? null) ? [$config] : [];
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     * @return array<string, mixed>|null
     */
    public static function write(array $connections): ?array
    {
        return $connections === [] ? null : ['connections' => array_values($connections)];
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     */
    public static function indexOf(array $connections, string $slug): ?int
    {
        foreach ($connections as $i => $c) {
            if (($c['psfe'] ?? null) === $slug) {
                return $i;
            }
        }

        return null;
    }
}
