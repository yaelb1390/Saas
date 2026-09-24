<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Borra una empresa POR COMPLETO: sus datos de negocio y su cuenta.
 *
 * No confundir con TenantDataPurger, que limpia los datos y conserva la cuenta para que el cliente
 * pueda volver a empezar. Esto es lo contrario: no queda nada.
 *
 * Es irreversible y no hay copia de seguridad automática detrás. Quien lo invoca debe haber
 * confirmado la identidad del operador (ver CompanyDeletionController).
 */
final class CompanyEraser
{
    /**
     * Resto de la cuenta, en orden de borrado (hijos antes que padres).
     *
     * @var list<string>
     */
    private const SHELL = [
        // OJO: la auditoría NO va aquí aunque también se borre. Se vacía al final de erase(),
        // porque este mismo borrado la sigue escribiendo mientras se ejecuta.

        // Roles y permisos por empresa: los vínculos antes que los roles.
        'model_has_permissions',
        'model_has_roles',
        'roles',
        // CRM
        'pipeline_stages',
        'pipelines',
        // Estructura
        'cash_registers',
        'warehouses',
        'branches',
        'subscriptions',
    ];

    public function __construct(private readonly TenantDataPurger $purger) {}

    public function erase(Company $company): void
    {
        $companyId = (int) $company->id;
        $nombre = (string) $company->name;

        DB::transaction(function () use ($company, $companyId): void {
            // 1. Datos de negocio, con el orden que ya conoce el purgador.
            $this->purger->purge($company);

            // 2. Los usuarios, A MANO y antes que la empresa.
            //
            // Es el paso que no se puede omitir: la clave foránea de users.company_id es SET NULL,
            // así que borrar la empresa NO borra su gente, la deja viva y sin empresa. Y un usuario
            // sin empresa no activa el filtro de aislamiento por empresa: seguiría pudiendo entrar
            // fuera de todo tenant. Dejarlo al descuido convertiría un borrado en un agujero.
            DB::table('users')->where('company_id', $companyId)->delete();

            foreach (self::SHELL as $tabla) {
                DB::table($tabla)->where('company_id', $companyId)->delete();
            }

            // 3. La empresa. Forzado: el modelo usa borrado lógico y un delete normal dejaría la
            // fila viva, con la empresa medio borrada y sus datos ya destruidos.
            $company->forceDelete();

            /*
             * 4. El rastro, AL FINAL y no antes.
             *
             * Todo lo de arriba está auditado —la empresa la primera— así que cada borrado escribe
             * filas NUEVAS en la auditoría mientras se ejecuta. Vaciarla antes solo la vaciaba a
             * medias y dejaba huérfano justo el rastro del propio borrado.
             *
             * Se va con la empresa a propósito, al revés que en la purga (ver
             * TenantDataPurger::KEPT): allí la cuenta sigue viva y el rastro sirve; aquí no queda ni
             * la empresa ni sus usuarios, y unas filas apuntando a nada no se pueden ni atribuir.
             */
            DB::table('audits')->where('company_id', $companyId)->delete();
            $this->borrarRastroDeErrores($companyId);
            $this->borrarRastroDeIncidentes($companyId);

            // Métricas (Fase 4): sin desglose que recalcular —cada fila ya es de una sola empresa,
            // al revés que los errores y los incidentes, que pueden compartirse entre varias—, así
            // que un DELETE liso es correcto y no deja nada que arreglar después.
            if (DbTable::existe('metric_buckets')) {
                DB::table('metric_buckets')->where('company_id', $companyId)->delete();
            }
        });

        // Queda constancia fuera de la base: los registros de auditoría de esta empresa acaban de
        // desaparecer con ella, así que el rastro del propio borrado tiene que vivir en otro sitio.
        SystemEvent::registrar(
            type: 'platform.company_erased',
            message: "Empresa «{$nombre}» eliminada por completo",
            contexto: ['empresa_id' => $companyId, 'operador' => auth()->user()?->email],
            level: SystemEvent::GRAVE,
        );

        /*
         * El registro del sistema NO se borra: se le quita la empresa.
         *
         * Es distinto de la auditoría y los errores, y la diferencia importa. Una fila de auditoría
         * dice «modificó Venta #418»: sin la venta ni la empresa no se puede ni leer. Una del
         * registro dice «Intento de acceso fallido con ana@x.com», y eso se entiende solo. Y sobre
         * todo, el borrado de esta empresa SE ANOTA AHÍ: destruirlo sería quedarse sin la única
         * prueba de que existió y de quién la eliminó.
         *
         * Va DESPUÉS de anotar el borrado y no dentro de la transacción, y el orden no es cosmético:
         * el propio suceso hereda la empresa activa —que es esta, recién borrada— si no se le pasa
         * otra, así que anonimizar antes lo dejaba fuera y quedaba una fila apuntando a una empresa
         * que ya no existe. Salió en el test que recorre todas las tablas.
         */
        DB::table('system_events')->where('company_id', $companyId)->update(['company_id' => null]);

        Log::warning('Empresa eliminada por completo', [
            'empresa_id' => $companyId,
            'empresa' => $nombre,
            'operador' => auth()->user()?->email,
        ]);
    }

    /**
     * Quita a la empresa de los errores, SIN llevarse los de las demás.
     *
     * Antes bastaba un `DELETE ... WHERE company_id = X`, porque un error era de UNA empresa. Ahora un
     * mismo error (un grupo) puede afectar a varias, y ese borrado se llevaría por delante errores de
     * empresas que siguen vivas. Lo correcto es restarle al grupo lo que esta empresa aportó y borrarlo
     * solo si se queda sin ninguna ocurrencia.
     *
     * Sin las tablas de desglose (la migración aún no se aplicó) el error sigue siendo de una sola
     * empresa, y se borra entero como siempre.
     */
    private function borrarRastroDeErrores(int $companyId): void
    {
        if (! DbTable::existe('error_event_companies')
            || ! DbTable::existe('error_event_users')
            || ! DbTable::tieneColumnas('error_events', ['fingerprint_version', 'companies_count', 'users_count'])) {
            DB::table('error_events')->where('company_id', $companyId)->delete();

            return;
        }

        $aportado = DB::table('error_event_companies')
            ->where('company_id', $companyId)
            ->get(['error_event_id', 'hits']);

        foreach ($aportado as $fila) {
            $quitar = (int) $fila->hits;

            DB::table('error_events')->where('id', $fila->error_event_id)->update([
                'hits' => DB::raw("CASE WHEN hits > {$quitar} THEN hits - {$quitar} ELSE 0 END"),
            ]);
        }

        $grupos = $aportado->pluck('error_event_id')->all();

        DB::table('error_event_companies')->where('company_id', $companyId)->delete();
        DB::table('error_event_users')->where('company_id', $companyId)->delete();

        if ($grupos !== []) {
            // Los totales de los grupos que la empresa dejó atrás, recalculados con lo que queda.
            DB::table('error_events')->whereIn('id', $grupos)->update([
                'companies_count' => DB::raw('(SELECT COUNT(*) FROM error_event_companies c WHERE c.error_event_id = error_events.id)'),
                'users_count' => DB::raw('(SELECT COUNT(*) FROM error_event_users u WHERE u.error_event_id = error_events.id)'),
            ]);

            // Los que solo eran de esta empresa se quedaron sin ninguna ocurrencia.
            DB::table('error_events')->whereIn('id', $grupos)->where('hits', '<=', 0)->delete();
        }

        // Un grupo anterior al desglose que nunca pasó por el backfill apunta a una sola empresa: era suyo.
        DB::table('error_events')
            ->where('company_id', $companyId)
            ->where('fingerprint_version', '<', 2)
            ->whereNotExists(function ($consulta): void {
                $consulta->select(DB::raw('1'))
                    ->from('error_event_companies')
                    ->whereColumn('error_event_companies.error_event_id', 'error_events.id');
            })
            ->delete();

        // Lo que quede apunta a «la última empresa conocida»: que no sea una que ya no existe.
        DB::table('error_events')->where('company_id', $companyId)->update(['company_id' => null]);
    }

    /**
     * Quita a la empresa del desglose de los incidentes, sin borrar el incidente.
     *
     * A diferencia de un grupo de errores, un incidente NO desaparece si se queda sin ninguna
     * empresa: es el registro de que algo pasó, y ese hecho sigue siendo cierto aunque la única
     * empresa a la que afectó ya no exista. Solo se recalcula `companies_count`.
     */
    private function borrarRastroDeIncidentes(int $companyId): void
    {
        if (! DbTable::existe('incident_companies')) {
            return;
        }

        $incidentes = DB::table('incident_companies')
            ->where('company_id', $companyId)
            ->pluck('incident_id');

        DB::table('incident_companies')->where('company_id', $companyId)->delete();

        if ($incidentes->isNotEmpty()) {
            DB::table('incidents')->whereIn('id', $incidentes)->update([
                'companies_count' => DB::raw('(SELECT COUNT(*) FROM incident_companies c WHERE c.incident_id = incidents.id)'),
            ]);
        }
    }
}
