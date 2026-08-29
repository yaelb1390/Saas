<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Models;

use App\Models\User;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Dealer\Enums\VehicleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una unidad del patio.
 *
 * Es una pieza única, no un producto con existencia: tiene su chasis, su costo de compra, sus gastos
 * de preparación y su precio. Por eso vive aparte de `products` y no dentro del inventario normal.
 *
 * @property VehicleStatus $status
 * @property int $company_id
 * @property string $code
 * @property string $purchase_cost
 * @property string $asking_price
 */
class Vehicle extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'code',
        'vin',
        'make',
        'model',
        'year',
        'trim',
        'color',
        'mileage',
        'fuel',
        'transmission',
        'plate',
        'photo_path',
        'purchase_cost',
        'asking_price',
        'status',
        'acquired_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
            // `decimal:2` porque SQLite devuelve los decimales como float y PostgreSQL como string:
            // sin el cast, bcmath falla en los tests y no en producción, que es lo peor que puede
            // pasar —el fallo aparecería justo donde nadie mira—.
            'purchase_cost' => 'decimal:2',
            'asking_price' => 'decimal:2',
            'acquired_at' => 'date',
            'year' => 'integer',
            'mileage' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(VehicleJob::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(VehicleDeal::class);
    }

    /**
     * La fila de una unidad, BLOQUEADA para decidir sobre ella.
     *
     * Vive aquí y no suelta dentro del servicio porque es la regla que impide vender el mismo carro
     * dos veces, y así se puede comprobar en un test: los tests corren sobre SQLite, que ignora
     * `FOR UPDATE` en silencio, de modo que la única forma de verificar el candado es compilar esta
     * consulta con la gramática de PostgreSQL, que es donde de verdad se aplica.
     *
     * Sin el candado, dos vendedores atendiendo a la vez leen «disponible» los dos, los dos pasan la
     * comprobación y los dos venden.
     *
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    public function scopeBloqueadaParaTrato(Builder $query, int $id): Builder
    {
        return $query->whereKey($id)->lockForUpdate();
    }

    /**
     * Si tiene foto guardada.
     *
     * Comprueba también que la COLUMNA exista: aquí las migraciones se aplican a mano, y entre que
     * sale el código y alguien migra la pantalla tiene que seguir pintándose sin fotos en vez de
     * caerse con «columna desconocida».
     */
    public function hasPhoto(): bool
    {
        return DbTable::tieneColumna('vehicles', 'photo_path')
            && $this->photo_path !== null
            && $this->photo_path !== '';
    }

    /**
     * La dirección de su foto, con versión.
     *
     * El `?v=` es la fecha de la última modificación: sin él, cambiar la foto de una unidad dejaría
     * la vieja en la caché del navegador durante días y parecería que el cambio no se guardó.
     */
    public function photoUrl(): ?string
    {
        if (! $this->hasPhoto()) {
            return null;
        }

        return route('panel.vehicles.photo', $this).'?v='.($this->updated_at?->timestamp ?? 0);
    }

    /** Cómo se llama para una persona: «Toyota Corolla 2019». */
    public function nombre(): string
    {
        return trim(implode(' ', array_filter([$this->make, $this->model, $this->year])));
    }

    /**
     * Lo que se gastó en dejarlo presentable.
     *
     * Se SUMA en el momento, no se guarda acumulado en `vehicles`. Un total guardado hay que
     * acordarse de actualizarlo cada vez que se anota un trabajo, y el día que alguien no lo haga el
     * margen miente sin avisar. Se usa `loadSum`/`withSum` desde fuera para no consultar por fila.
     */
    public function gastos(): string
    {
        /*
         * Se mira si el atributo ESTÁ, no si tiene valor.
         *
         * Con `?? ` esto disparaba una consulta por cada unidad SIN trabajos, porque `withSum`
         * devuelve NULL —no cero— cuando no hay filas que sumar, y el `??` lo tomaba por «no está
         * cargado». Y como `costoReal()` y `margen()` también llaman aquí, salían tres consultas por
         * fila: 102 para treinta vehículos. Lo cazó el test que fija el coste de la pantalla.
         */
        $sum = array_key_exists('jobs_sum_cost', $this->attributes)
            ? ($this->attributes['jobs_sum_cost'] ?? 0)
            : $this->jobs()->sum('cost');

        return number_format((float) $sum, 2, '.', '');
    }

    /** Lo que costó de verdad: la compra más la preparación. */
    public function costoReal(): string
    {
        return bcadd($this->purchase_cost ?? '0', $this->gastos(), 2);
    }

    /**
     * Lo que se ganaría al precio pedido.
     *
     * Es un número que NO se le enseña a quien solo vende: sale del costo, y el costo no viaja al
     * navegador de un vendedor. Quien lo pinte tiene que haber comprobado el permiso antes.
     */
    public function margen(): string
    {
        return bcsub($this->asking_price ?? '0', $this->costoReal(), 2);
    }
}
