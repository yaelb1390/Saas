<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

/**
 * Provincias y municipios con los códigos de la DGII, leídos del XSD OFICIAL (`ProvinciaMunicipioType`
 * de ecf-31.xsd): el código es la enumeración y el nombre, el comentario que la acompaña. No se copia
 * la lista a mano: si la DGII la cambia, cambia con el esquema.
 *
 * Código de 6 dígitos PPMMDD: `PP0000` provincia (o Distrito Nacional), `PPMM00` municipio y `PPMMDD`
 * distrito municipal «(D. M.)».
 */
final class TerritoryCatalog
{
    /** @var array<string, string>|null */
    private ?array $todos = null;

    public function __construct(private readonly SchemaRegistry $schemas) {}

    /** @return array<string, string> código => nombre */
    public function provinces(): array
    {
        return array_filter($this->all(), fn (string $c): bool => str_ends_with($c, '0000'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Municipios y distritos municipales (todo lo que no es provincia), agrupables por los 2 primeros
     * dígitos de su provincia.
     *
     * @return array<string, string>
     */
    public function municipalities(): array
    {
        return array_filter($this->all(), fn (string $c): bool => ! str_ends_with($c, '0000'), ARRAY_FILTER_USE_KEY);
    }

    public function isProvince(?string $code): bool
    {
        return $code !== null && array_key_exists($code, $this->provinces());
    }

    public function isMunicipality(?string $code): bool
    {
        return $code !== null && array_key_exists($code, $this->municipalities());
    }

    public function name(?string $code): ?string
    {
        return $code === null ? null : ($this->all()[$code] ?? null);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->todos !== null) {
            return $this->todos;
        }

        $xsd = (string) file_get_contents($this->schemas->path('ecf-31.xsd'));
        $inicio = strpos($xsd, 'name="ProvinciaMunicipioType"');
        $fin = $inicio === false ? false : strpos($xsd, '</xs:simpleType>', $inicio);
        $bloque = $inicio === false || $fin === false ? '' : substr($xsd, $inicio, $fin - $inicio);

        preg_match_all('/<xs:enumeration\s+value=\s*"(\d{6})"\s*\/>\s*<!--(.*?)-->/u', $bloque, $m, PREG_SET_ORDER);

        $lista = [];
        foreach ($m as [, $codigo, $nombre]) {
            $lista[$codigo] = $this->limpiar($nombre);
        }

        return $this->todos = $lista;
    }

    /** «MUNICIPIO SANTO DOMINGO DE GUZMÁN     » → «Santo Domingo de Guzmán». */
    private function limpiar(string $nombre): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
        $n = preg_replace('/^(PROVINCIA|MUNICIPIO)\s+/u', '', $n) ?? $n;
        $n = mb_convert_case(mb_strtolower($n), MB_CASE_TITLE);

        return preg_replace_callback('/\b(De|Del|La|Las|Los|Y|El)\b/u', fn ($x) => mb_strtolower($x[0]), $n) ?? $n;
    }
}
