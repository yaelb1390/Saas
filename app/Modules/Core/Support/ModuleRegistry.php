<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Catálogo central de los módulos comercializables del sistema.
 *
 * Una empresa contrata un subconjunto de estos módulos (el plan). Dashboard, Reportes y la
 * administración de Usuarios son el núcleo: siempre están disponibles y no se listan aquí.
 *
 * La clave es estable (se guarda en la base de datos); la etiqueta es solo presentación.
 */
final class ModuleRegistry
{
    /**
     * @var array<string, string>
     */
    private const MODULES = [
        'pos' => 'Punto de Venta',
        // Terminal táctil de cobro (heladería, comida rápida). Módulo aparte del POS de mostrador
        // para poder venderlo por separado, aunque ambos comparten el motor de cobro.
        'quick_pos' => 'Venta rápida',
        'inventory' => 'Inventario',
        'sales' => 'Ventas',
        'quotes' => 'Cotizaciones',
        'purchasing' => 'Compras',
        'crm' => 'CRM',
        'whatsapp' => 'WhatsApp',
        // Publicar en Instagram, Facebook y demás desde el panel. Va aparte de «whatsapp» porque son
        // dos cosas distintas: aquello es conversación con un cliente concreto, esto es difusión.
        'social' => 'Redes sociales',
        // Contestar preguntas de precio en los comentarios de Instagram con reglas por producto.
        // Aparte de «social» a propósito: se vende como producto propio, no como una pestaña más de
        // publicación (ver docs/social-commerce/SOCIAL_COMMERCE_ARCHITECTURE.md, sección 1).
        'social_commerce' => 'Social Commerce',
        'billing' => 'Facturación',
        'finance' => 'Finanzas',
        'loans' => 'Préstamos',
        'delivery' => 'Entregas',
        'hr' => 'Recursos Humanos',
        // Dealer de vehículos. Aparte del inventario porque un carro no es un producto: es una
        // pieza única con su chasis, su costo y su precio, y se vende a un negocio distinto.
        'dealer' => 'Vehículos',
        // Depende de «dealer»: el catálogo (cada unidad, sus fotos, sus papeles) sigue viviendo ahí.
        // Este módulo es la OPERACIÓN de alquilarlas —reservas, entrega, devolución, contratos—, y por
        // eso una empresa necesita los dos activos para usarlo (ver EnsureModuleActive en las rutas).
        'rental' => 'Alquiler de Vehículos',
        'ai' => 'IA & RAG',
        'reports' => 'Reportes',
        'printing' => 'Centro de Impresión',
    ];

    /**
     * Qué hace cada módulo, en una frase, para quien todavía no es cliente.
     *
     * Va aparte de MODULES y no dentro de cada entrada porque `all()` devuelve «clave => etiqueta» y
     * varias pantallas lo recorren así; cambiar esa forma las rompería a todas para ganar poco.
     *
     * Están escritas para un dueño de negocio, no para un programador: dicen qué resuelven, no cómo
     * se llaman por dentro.
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'pos' => 'Cobra en mostrador con lector de código de barras y control de caja.',
        'quick_pos' => 'Terminal táctil para cobrar rápido, con fotos, tamaños y sabores.',
        'inventory' => 'Productos, existencias por almacén y entradas de mercancía.',
        'sales' => 'Historial de ventas, recibos y devoluciones.',
        'quotes' => 'Ofrece precios por escrito, mándalos por WhatsApp y cóbralos con un botón.',
        'purchasing' => 'Proveedores y órdenes de compra para reponer existencias.',
        'crm' => 'Ficha de cada cliente, su historial y las oportunidades de venta.',
        'whatsapp' => 'Atiende y responde a tus clientes por WhatsApp desde el sistema.',
        'social' => 'Publica en Instagram, Facebook y TikTok a la vez, ahora o programado.',
        'social_commerce' => 'Contesta el precio en los comentarios de Instagram al momento, con tus productos y sin escribir cada respuesta a mano.',
        'billing' => 'Comprobantes fiscales con NCF y los reportes 606 y 607 de la DGII.',
        'finance' => 'Cuentas, ingresos y gastos para saber en qué se va el dinero.',
        'loans' => 'Préstamos con sus cuotas, pagos y mora al día.',
        'delivery' => 'Reparto a domicilio con seguimiento de cada entrega.',
        'hr' => 'Empleados, asistencia y su portal para consultar sus datos.',
        'dealer' => 'Patio de vehículos: cada unidad con su chasis, lo que costó prepararla y a cuánto se vendió.',
        'rental' => 'Reservas, entregas, devoluciones y contratos de alquiler. Necesita también el módulo Vehículos.',
        'ai' => 'Asistente que responde sobre tus propios documentos y ventas.',
        'reports' => 'Informes de ventas, ganancias y productos más vendidos.',
        'printing' => 'Impresoras Bluetooth, USB y de red, plantillas y una impresora por módulo.',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::MODULES;
    }

    /**
     * Frase que explica el módulo. Cadena vacía si no se ha escrito: quien la pinte debe poder
     * omitirla sin enseñar un hueco.
     */
    public static function description(string $key): string
    {
        return self::DESCRIPTIONS[$key] ?? '';
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function label(string $key): string
    {
        return self::MODULES[$key] ?? ucfirst($key);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::MODULES);
    }

    /**
     * Filtra una lista de claves dejando solo las válidas (defensa ante datos manipulados).
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    public static function sanitize(array $keys): array
    {
        return array_values(array_filter(
            array_map('strval', $keys),
            static fn (string $key): bool => self::exists($key),
        ));
    }
}
