<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Borra los DATOS de negocio de una empresa (productos, ventas, préstamos, clientes, finanzas, etc.)
 * conservando el "shell" de la cuenta: empresa, usuarios, sucursales, almacenes, cajas, suscripción,
 * pipelines de CRM y los roles/permisos. Se usa para las pruebas self-service que no se convierten en
 * plan: pasados 24 h del vencimiento se limpian sus datos, pero el cliente mantiene su acceso para
 * contratar y empezar de cero.
 *
 * Se borra tabla por tabla con DB::table()->where('company_id', ...)->delete() (evita el global scope
 * de empresa). El ORDEN va de hijos a padres para respetar las FK `restrict` internas (p. ej.
 * sale_items→products, loans→customers, purchase_orders→suppliers).
 *
 * IMPORTANTE: si un módulo nuevo agrega una tabla con `company_id`, hay que añadirla a TABLES en la
 * posición correcta —o a KEPT si se conserva a propósito— o sus filas quedarán huérfanas.
 *
 * Ese aviso estuvo aquí escrito y aun así se incumplió: seis tablas quedaron fuera. Por eso ahora lo
 * comprueba un test (TenantPurgeCompletenessTest), que falla si aparece una tabla con `company_id`
 * que no esté en ninguna de las dos listas. Un comentario pide atención; un test la exige.
 */
final class TenantDataPurger
{
    /**
     * Tablas de datos de negocio, en orden de borrado (hijos antes que padres). NO incluye el shell:
     * companies, users, branches, warehouses, cash_registers, subscriptions, pipelines,
     * pipeline_stages ni las tablas de roles/permisos.
     *
     * @var list<string>
     */
    public const TABLES = [
        // Redes sociales. A quién se le dio la bienvenida SÍ se purga: es una lista de personas con
        // las que habló su negocio, y eso son sus datos. Que alguien vuelva a recibir el saludo si
        // escribe otra vez es inofensivo; conservar con quién habló después de un vaciado, no.
        'social_welcomes',
        // IA
        // Las preguntas al asistente SÍ se purgan, al revés que la auditoría.
        //
        // La auditoría es rastro nuestro —qué hizo el sistema— y por eso se conserva. Esto es texto
        // que escribió su gente, y una pregunta puede llevar dentro el nombre de un cliente o de un
        // producto. Cuando alguien pide que se vacíen sus datos, eso son sus datos.
        //
        // Lo que se pierde —saber qué le falta al manual— es agregado entre todas las empresas y
        // sobrevive en las demás.
        'assistant_questions',
        'ai_sentiment_analyses',
        'ai_document_chunks',
        'ai_documents',
        // WhatsApp
        'wa_messages',
        'wa_conversations',
        'wa_templates',
        // RRHH
        'attendances',
        'employees',
        // Finanzas
        // Entradas de mercancía: las líneas apuntan a la remesa y a los productos, así que van antes
        // que ambos.
        'goods_receipt_lines',
        'goods_receipts',
        // Gastos antes que `accounts` y que `expense_categories`: apuntan a las dos.
        'expenses',
        'expense_categories',
        'financial_movements',
        'accounts',
        // Entregas
        'deliveries',
        // Préstamos (installments/payments también cuelgan de loans, pero se listan por company_id).
        // Las solicitudes van PRIMERO: apuntan al préstamo que salió de ellas, así que borrar
        // `loans` antes dejaría una clave foránea colgando.
        'loan_applications',
        'loan_payments',
        'loan_installments',
        'loans',
        // Facturación
        'invoice_items',
        'invoices',
        'fiscal_sequences',
        // Ventas (las opciones vendidas cuelgan de la línea: van antes)
        'sale_item_options',
        'sale_items',
        'sales',
        // Compras
        'purchase_invoices',
        'purchase_order_items',
        'purchase_orders',
        'suppliers',
        // Caja. Los tickets aparcados apuntan a la sesión de caja, así que se borran antes.
        'held_orders',
        'cash_movements',
        'cash_sessions',
        // CRM (los pipelines/stages se conservan)
        'customer_documents',
        'opportunities',
        'customers',
        // Opciones de producto. El pivote y las opciones apuntan a productos y a grupos: los tres
        // van antes que `products`.
        'product_option_group',
        'options',
        'option_groups',
        // Inventario
        'stock_movements',
        'stock',
        'products',
        'categories',
    ];

    /**
     * Tablas con `company_id` que NO se purgan, y por qué. Existe para que el test de completitud
     * pueda distinguir «se conserva a propósito» de «se olvidó»: sin esta lista, ese test no
     * podría decir la diferencia y no serviría de nada.
     *
     * @var list<string>
     */
    public const KEPT = [
        // El rastro SOBREVIVE a la purga, y es deliberado: la cuenta sigue viva y sus datos se
        // vaciaron, que es justo cuando hace falta poder mirar qué pasó y quién lo hizo. Borrarlo
        // aquí sería destruir la única prueba del propio vaciado.
        //
        // Al BORRAR la empresa del todo sí se van (al final de CompanyEraser::erase): entonces no
        // queda ni la empresa ni sus usuarios, y unas filas huérfanas no se pueden ni atribuir.
        'audits',
        'error_events',

        /*
         * La configuración de la bienvenida se CONSERVA, y no por comodidad.
         *
         * Guarda el token y el secreto con los que Zernio nos avisa. Si se borraran, el webhook ya
         * registrado allí seguiría existiendo y disparando contra una dirección que ya no reconoce
         * a nadie: cada mensaje que recibiera el cliente le generaría un error en su panel de
         * Zernio, y nadie sabría por qué.
         *
         * Además es coherente con lo que ya pasa: la clave de Zernio vive en `companies`, que también
         * se conserva. La configuración de redes sobrevive a una purga; los datos, no.
         */
        'social_welcome_settings',

        // El «shell» de la cuenta: la empresa sigue existiendo y su gente puede volver a entrar.
        'companies',
        'users',
        'branches',
        'warehouses',
        'cash_registers',
        'subscriptions',
        'pipelines',
        'pipeline_stages',
        // Roles y permisos por empresa (spatie).
        'roles',
        'model_has_roles',
        'model_has_permissions',
        // Bitácora de avisos de la pasarela de cobro. Es de la plataforma, no de la empresa, y su
        // company_id se pone a NULL solo al borrar la empresa: el rastro de un pago debe sobrevivir.
        'polar_webhook_events',
    ];

    public function purge(Company $company): void
    {
        $companyId = (int) $company->id;

        DB::transaction(function () use ($companyId): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('company_id', $companyId)->delete();
            }
        });
    }
}
