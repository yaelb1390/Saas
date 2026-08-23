<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Models;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Cómo atiende el bot de una empresa, y por dónde.
 *
 * La vía de conexión (`provider`) vive aquí y no en una tabla propia porque esta es la única tabla de
 * ajustes de WhatsApp por empresa, y crear otra de una sola columna sería peor: dos filas que
 * consultar y mantener sincronizadas para guardar dos datos del mismo negocio.
 *
 * @property string $provider
 * @property bool $is_active
 * @property string|null $business_info
 * @property string|null $greeting
 */
final class WaBotSetting extends Model
{
    use BelongsToCompany;

    /** Lo que cabe en el texto del negocio. Suficiente para horario, pagos, envíos y devoluciones. */
    public const MAX_INFO = 4000;

    /**
     * Emparejamiento por código QR (Evolution API).
     *
     * Dos minutos, cualquier número y gratis. Por debajo es la sesión de WhatsApp Web, NO la API
     * oficial: Meta puede bloquear el número. Y necesita un servidor propio encendido, así que desde
     * producción no se alcanza salvo que alguien monte un túnel.
     */
    public const POR_QR = 'evolution';

    /**
     * API oficial de Meta, a través de Zernio.
     *
     * Sin riesgo de bloqueo y sin infraestructura propia. Para este bot no cuesta dinero: Meta no
     * cobra los mensajes de servicio dentro de la ventana de 24 h, y el bot solo contesta a quien
     * acaba de escribir. A cambio no hay QR y el número no puede estar en la app de WhatsApp.
     */
    public const OFICIAL = 'zernio';

    /** @var list<string> */
    public const VIAS = [self::OFICIAL, self::POR_QR];

    protected $fillable = ['company_id', 'provider', 'is_active', 'business_info', 'greeting'];

    /** La fila de una empresa, apagada la primera vez. */
    public static function paraEmpresa(int $companyId): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId],
            ['is_active' => false],
        );
    }

    /**
     * Lo que hay, sin crear nada y sin caerse si la tabla todavía no existe.
     *
     * Es la versión para PINTAR: la de arriba escribe, y una pantalla que se abre no debería insertar
     * filas. Y devuelve null en vez de reventar porque aquí las migraciones se aplican a mano y el
     * despliegue no las corre: entre que sale el código y alguien migra, esta tabla no está. Sin la
     * comprobación, la bandeja entera daría un 500 —que es literalmente lo que le pasó a la pantalla
     * de Redes sociales—.
     */
    public static function paraEmpresaSiHay(): ?self
    {
        if (! DbTable::existe('wa_bot_settings')) {
            return null;
        }

        return self::query()->first();
    }

    /** ¿Puede contestar? Encendido y con algo que decir: sin información, inventaría. */
    public function puedeContestar(): bool
    {
        return $this->is_active && filled($this->business_info);
    }

    /** ¿Esta empresa va por la vía oficial? */
    public function usaZernio(): bool
    {
        return $this->provider === self::OFICIAL;
    }

    /**
     * ¿Se puede escribir a un número con el que no se ha hablado nunca?
     *
     * Por la vía oficial NO, y no es una carencia del proveedor: Meta exige que la ventana la abra el
     * cliente, y fuera de ella solo se puede mandar una plantilla aprobada. Se pregunta desde la
     * pantalla para poder desactivar el botón con su explicación en vez de dejar que falle el envío.
     */
    public function puedeEscribirPrimero(): bool
    {
        return ! $this->usaZernio();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }
}
