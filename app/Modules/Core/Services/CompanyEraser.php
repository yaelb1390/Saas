<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
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
            DB::table('error_events')->where('company_id', $companyId)->delete();
        });

        // Queda constancia fuera de la base: los registros de auditoría de esta empresa acaban de
        // desaparecer con ella, así que el rastro del propio borrado tiene que vivir en otro sitio.
        Log::warning('Empresa eliminada por completo', [
            'empresa_id' => $companyId,
            'empresa' => $nombre,
            'operador' => auth()->user()?->email,
        ]);
    }
}
