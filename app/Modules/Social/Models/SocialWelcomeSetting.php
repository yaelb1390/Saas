<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * La configuración de la bienvenida de una empresa.
 *
 * @property bool $is_active
 * @property string $message
 * @property array<int, string>|null $variations
 * @property string $token
 * @property string $secret
 */
final class SocialWelcomeSetting extends Model
{
    use BelongsToCompany;

    /** Cuántas variaciones se admiten, además del mensaje principal. */
    public const MAX_VARIACIONES = 3;

    protected $fillable = ['company_id', 'is_active', 'message', 'variations'];

    /**
     * La fila de una empresa, creándola apagada si no existe.
     *
     * El token y el secreto se generan AQUÍ y una sola vez: si se regeneraran al guardar, cada
     * cambio de texto dejaría el webhook ya registrado en Zernio apuntando a una dirección que ya no
     * responde, y la bienvenida dejaría de salir sin que nadie tocara el interruptor.
     */
    public static function paraEmpresa(int $companyId): self
    {
        $ajustes = self::withoutGlobalScopes()->where('company_id', $companyId)->first();

        if ($ajustes !== null) {
            return $ajustes;
        }

        /*
         * `forceFill` y no `create()`: el token y el secreto NO están en `$fillable` a propósito.
         *
         * Son las dos credenciales que hacen que un aviso se acepte, así que no pueden poder llegar
         * desde un formulario ni por descuido: quien pudiera fijarlas se fabricaría avisos válidos y
         * mandaría mensajes desde la cuenta de Instagram de un cliente.
         */
        $ajustes = new self;

        $ajustes->forceFill([
            'company_id' => $companyId,
            'is_active' => false,
            'message' => '¡Gracias por escribirnos! Cuéntanos qué necesitas y te ayudamos enseguida.',
            'token' => Str::random(48),
            'secret' => Str::random(64),
        ])->save();

        return $ajustes;
    }

    /**
     * Un texto de los que hay, al azar.
     *
     * Al azar y no por turnos: llevar la cuenta obligaría a escribir en la base en cada mensaje solo
     * para saber cuál toca, y lo que se busca —que no salga siempre el mismo— se consigue igual.
     */
    public function textoParaEnviar(): string
    {
        $opciones = array_values(array_filter(
            array_merge([$this->message], $this->variations ?? []),
            static fn (mixed $t): bool => is_string($t) && trim($t) !== '',
        ));

        return $opciones === [] ? $this->message : $opciones[array_rand($opciones)];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'variations' => 'array',
            'last_sent_at' => 'datetime',
            // Quien tenga el secreto puede fabricar avisos falsos y hacer que le escribamos a quien
            // quiera desde la cuenta del cliente. Cifrado en reposo, como la clave de Zernio.
            'secret' => 'encrypted',
        ];
    }
}
