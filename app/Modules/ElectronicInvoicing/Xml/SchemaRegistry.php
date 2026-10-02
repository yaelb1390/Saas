<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use App\Modules\ElectronicInvoicing\Domain\EcfType;

/**
 * Los esquemas XSD oficiales de la DGII que lleva el repositorio, y la comprobación de que siguen
 * siendo los que se descargaron.
 *
 * Los XSD se guardan tal cual vienen de la DGII (algunos con BOM) y su huella va en el manifiesto.
 * Si alguien los «arregla» a mano, los documentos dejarían de validarse contra lo oficial sin que
 * nada avisara: por eso el diagnóstico compara la huella antes de dar los esquemas por buenos.
 */
final class SchemaRegistry
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function pathForType(EcfType $type): string
    {
        return $this->path($type->schemaFile());
    }

    public function path(string $file): string
    {
        return rtrim((string) config('ecf.spec.path'), '/\\').DIRECTORY_SEPARATOR.$file;
    }

    /** Fecha de publicación de la DGII del esquema de un tipo (la versión «1.0» no basta). */
    public function schemaDate(EcfType $type): ?string
    {
        return $this->manifest()['archivos'][$type->schemaFile()]['fecha_dgii'] ?? null;
    }

    /**
     * Estado de cada esquema del manifiesto: falta, alterado o correcto.
     *
     * @return array<string, array{estado: 'ok'|'falta'|'alterado', fecha_dgii: string|null}>
     */
    public function integrity(): array
    {
        $resultado = [];

        foreach ($this->manifest()['archivos'] ?? [] as $archivo => $datos) {
            $ruta = $this->path($archivo);

            $estado = match (true) {
                ! is_file($ruta) => 'falta',
                hash_file('sha256', $ruta) !== ($datos['sha256'] ?? null) => 'alterado',
                default => 'ok',
            };

            $resultado[$archivo] = ['estado' => $estado, 'fecha_dgii' => $datos['fecha_dgii'] ?? null];
        }

        return $resultado;
    }

    public function allIntact(): bool
    {
        $integridad = $this->integrity();

        return $integridad !== [] && collect($integridad)->every(fn (array $e): bool => $e['estado'] === 'ok');
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $ruta = (string) config('ecf.spec.manifest');
        $contenido = is_file($ruta) ? file_get_contents($ruta) : false;
        $datos = $contenido === false ? null : json_decode($contenido, true);

        return $this->manifest = is_array($datos) ? $datos : [];
    }
}
