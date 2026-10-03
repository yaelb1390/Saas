<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use App\Modules\ElectronicInvoicing\Domain\EcfType;
use RuntimeException;

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

    public function validationPathForType(EcfType $type): string
    {
        return $this->validationPath($type->schemaFile());
    }

    /**
     * La ruta con la que se VALIDA: el archivo oficial tal cual, o —si tiene erratas registradas en
     * `ecf.schema_errata`— una copia corregida en el directorio temporal (el único escribible en
     * producción). El archivo oficial nunca se modifica.
     */
    public function validationPath(string $file): string
    {
        // No `config("ecf.schema_errata.{$file}")`: el punto de «ecf-31.xsd» lo partiría en dos claves.
        $erratas = (array) (((array) config('ecf.schema_errata', []))[$file] ?? []);

        if ($erratas === []) {
            return $this->path($file);
        }

        $original = (string) file_get_contents($this->path($file));
        $huella = substr(hash('sha256', $original.json_encode($erratas)), 0, 16);
        $destino = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bmos-ecf-xsd'.DIRECTORY_SEPARATOR.$huella.DIRECTORY_SEPARATOR.$file;

        if (! is_file($destino)) {
            $corregido = $original;

            foreach ($erratas as $errata) {
                $corregido = $this->aplicar($file, $corregido, $errata);
            }

            if (! is_dir(dirname($destino))) {
                mkdir(dirname($destino), 0775, true);
            }

            file_put_contents($destino, $corregido, LOCK_EX);
        }

        return $destino;
    }

    /** @param  array<string, string>  $errata */
    private function aplicar(string $file, string $xsd, array $errata): string
    {
        return match ($errata['type'] ?? null) {
            'replace' => $this->reemplazar($file, $xsd, $errata),
            'copy_simple_type' => $this->copiarTipo($file, $xsd, $errata),
            default => throw new RuntimeException("Errata desconocida para {$file}."),
        };
    }

    /** @param  array<string, string>  $errata */
    private function reemplazar(string $file, string $xsd, array $errata): string
    {
        if (! str_contains($xsd, $errata['search'])) {
            throw new RuntimeException("La errata de {$file} ya no aplica (no aparece «{$errata['search']}»): puede que la DGII haya corregido el archivo. Revisa config/ecf.php → schema_errata.");
        }

        return str_replace($errata['search'], $errata['replace'], $xsd);
    }

    /** @param  array<string, string>  $errata */
    private function copiarTipo(string $file, string $xsd, array $errata): string
    {
        $nombre = $errata['name'];

        if (str_contains($xsd, 'simpleType name="'.$nombre.'"')) {
            throw new RuntimeException("La errata de {$file} ya no aplica: el archivo ya define {$nombre}. Revisa config/ecf.php → schema_errata.");
        }

        $fuente = (string) file_get_contents($this->path($errata['from']));

        if (preg_match('/<xs:simpleType name="'.preg_quote($nombre, '/').'">.*?<\/xs:simpleType>/s', $fuente, $m) !== 1) {
            throw new RuntimeException("No se encontró {$nombre} en {$errata['from']} para corregir {$file}.");
        }

        $cierre = strrpos($xsd, '</xs:schema>');

        if ($cierre === false) {
            throw new RuntimeException("{$file} no termina en </xs:schema>.");
        }

        return substr($xsd, 0, $cierre).$m[0]."\n".substr($xsd, $cierre);
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
