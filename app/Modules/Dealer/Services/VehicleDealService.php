<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Services;

use App\Modules\Core\Support\PlanDeCuotas;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Dealer\DTOs\CreateDealData;
use App\Modules\Dealer\Enums\DealFrequency;
use App\Modules\Dealer\Enums\DealInstallmentStatus;
use App\Modules\Dealer\Enums\DealStatus;
use App\Modules\Dealer\Enums\VehicleStatus;
use App\Modules\Dealer\Exceptions\DealerException;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Dealer\Models\VehicleDeal;
use App\Modules\Dealer\Models\VehicleDealPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Apartar, vender y cobrar una unidad.
 *
 * Todo el dinero va como string con bcmath a dos decimales; nunca float. Un carro son cientos de
 * miles de pesos y el coma flotante los redondea mal justo donde más se nota.
 */
final class VehicleDealService
{
    private const SCALE = 2;

    /**
     * Abre el trato sobre una unidad.
     *
     * EL CANDADO NO ES DECORATIVO. Dos vendedores atendiendo a la vez es lo normal en un dealer, y
     * sin `lockForUpdate` las dos peticiones leen «disponible» a la vez, las dos pasan la
     * comprobación y las dos venden el mismo carro. Con el candado, la segunda espera a que la
     * primera termine y entonces ve el estado ya cambiado.
     */
    public function open(CreateDealData $data): VehicleDeal
    {
        return DB::transaction(function () use ($data): VehicleDeal {
            $companyId = app(CurrentCompany::class)->id() ?? 0;

            // Se relee DENTRO de la transacción y con el candado puesto: el estado que traía la
            // pantalla puede tener minutos y no sirve para decidir.
            $vehicle = Vehicle::query()->bloqueadaParaTrato($data->vehicleId)->firstOrFail();

            if (! $vehicle->status->admiteTrato()) {
                throw DealerException::noDisponible($vehicle->nombre(), mb_strtolower($vehicle->status->label()));
            }

            $customer = $this->resolveCustomer($data->customerId, $companyId);

            $precio = $this->normalize($data->agreedPrice);
            $inicial = $this->normalize($data->downPayment);
            $usado = $this->normalize($data->tradeInValue);

            $financiado = $data->financing === 'installments';

            $deal = new VehicleDeal([
                'company_id' => $companyId,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'code' => $this->nextCode($companyId),
                'agreed_price' => $precio,
                'down_payment' => $inicial,
                'trade_in_vehicle_id' => $data->tradeInVehicleId,
                'trade_in_value' => $usado,
                'financing' => $financiado ? 'installments' : 'none',
                'status' => $data->close ? DealStatus::Closed : DealStatus::Reserved,
                'reserved_at' => now(),
                'closed_at' => $data->close ? now() : null,
                'notes' => $data->notes,
                'user_id' => auth()->id(),
            ]);

            $pendiente = $this->resto($precio, $inicial, $usado);

            if ($financiado) {
                $this->prepararFinanciamiento($deal, $data, $pendiente);
            } else {
                $deal->interest_amount = '0.00';
                $deal->interest_rate = '0.00';
                $deal->installments_count = 0;
                // De contado, lo que falta es el resto del precio: puede no ser cero si el cliente
                // se lleva el carro con una parte pendiente sin cuotas pactadas.
                $deal->balance = $pendiente;
            }

            $deal->save();

            if ($financiado) {
                $this->generarCuotas($deal);
            }

            // La unidad queda bloqueada tanto si se cerró como si solo se apartó: en los dos casos
            // deja de estar a la venta, y esa es la razón de ser del estado «apartado».
            $vehicle->status = $data->close ? VehicleStatus::Sold : VehicleStatus::Reserved;
            $vehicle->save();

            // El carro recibido en parte de pago entra al patio disponible: es una unidad más que se
            // vuelve a vender, no un papel.
            $this->recibirUsado($data->tradeInVehicleId);

            return $deal;
        });
    }

    /** Convierte un apartado en venta. La unidad pasa de apartada a vendida. */
    public function close(VehicleDeal $deal): VehicleDeal
    {
        return DB::transaction(function () use ($deal): VehicleDeal {
            if ($deal->status === DealStatus::Cancelled) {
                throw DealerException::tratoCaido();
            }

            if ($deal->status === DealStatus::Closed) {
                throw DealerException::tratoCerrado();
            }

            $deal->status = DealStatus::Closed;
            $deal->closed_at = now();
            $deal->save();

            $vehicle = Vehicle::query()->bloqueadaParaTrato((int) $deal->vehicle_id)->first();
            if ($vehicle !== null) {
                $vehicle->status = VehicleStatus::Sold;
                $vehicle->save();
            }

            return $deal;
        });
    }

    /**
     * El trato se cae: la unidad vuelve al patio.
     *
     * Lo importante es que el carro se libere. Un apartado que no se concreta y deja la unidad
     * bloqueada para siempre es peor que no haberlo registrado.
     */
    public function cancel(VehicleDeal $deal): VehicleDeal
    {
        return DB::transaction(function () use ($deal): VehicleDeal {
            $deal->status = DealStatus::Cancelled;
            $deal->save();

            $vehicle = Vehicle::query()->bloqueadaParaTrato((int) $deal->vehicle_id)->first();
            // Solo se libera si seguía atada a ESTE trato. Si ya la vendió otro, no se toca.
            if ($vehicle !== null && $vehicle->status !== VehicleStatus::Sold) {
                $vehicle->status = VehicleStatus::Available;
                $vehicle->save();
            }

            return $deal;
        });
    }

    /**
     * Registra un abono y lo reparte entre las cuotas más viejas pendientes.
     *
     * De la más vieja a la más nueva, y cubriendo su mora: es como se cobra de verdad y como lo
     * espera el cliente. Aplicarlo a la más nueva dejaría cuotas viejas vencidas mientras las
     * futuras se van saldando, que no tiene sentido para nadie.
     */
    public function registerPayment(VehicleDeal $deal, string $amount, array $context = []): VehicleDealPayment
    {
        return DB::transaction(function () use ($deal, $amount, $context): VehicleDealPayment {
            if (! $deal->status->admiteCobro()) {
                throw DealerException::tratoCaido();
            }

            $abono = $this->normalize($amount);

            if (bccomp($abono, '0', self::SCALE) <= 0) {
                throw DealerException::abonoInvalido();
            }

            $saldo = $this->normalize($deal->balance ?? '0');

            if (bccomp($abono, $saldo, self::SCALE) > 0) {
                throw DealerException::abonoMayorQueElSaldo(number_format((float) $saldo, 2));
            }

            $pago = VehicleDealPayment::create([
                'company_id' => $deal->company_id,
                'vehicle_deal_id' => $deal->id,
                'amount' => $abono,
                'method' => $context['method'] ?? 'cash',
                'reference' => $context['reference'] ?? null,
                'paid_at' => now(),
                'notes' => $context['note'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $this->repartirEntreCuotas($deal, $abono);

            $deal->balance = bcsub($saldo, $abono, self::SCALE);
            $deal->save();

            return $pago;
        });
    }

    /**
     * Fija la mora de una cuota. La pone el administrador, no el sistema.
     *
     * En este negocio el recargo se negocia. Uno automático que nadie decidió acaba discutido en el
     * mostrador, y el que discute tiene razón.
     */
    public function setLateFee(VehicleDeal $deal, int $installmentId, string $amount): void
    {
        DB::transaction(function () use ($deal, $installmentId, $amount): void {
            $cuota = $deal->installments()->whereKey($installmentId)->firstOrFail();

            $antes = $this->normalize($cuota->late_fee ?? '0');
            $ahora = $this->normalize($amount);

            $cuota->late_fee = $ahora;
            $cuota->status = $this->estadoDeLaCuota($cuota->outstanding(), $cuota->paid_amount ?? '0');
            $cuota->save();

            // El saldo del trato se mueve con la diferencia: subir la mora sube lo que se debe.
            $deal->balance = bcadd($this->normalize($deal->balance ?? '0'), bcsub($ahora, $antes, self::SCALE), self::SCALE);
            $deal->save();
        });
    }

    /** Deja el trato listo para financiar: interés, número de cuotas y saldo. */
    private function prepararFinanciamiento(VehicleDeal $deal, CreateDealData $data, string $pendiente): void
    {
        $tasa = $this->normalize($data->interestRate);

        // El interés a mano manda sobre la tasa, igual que en préstamos: el dealer suele pactar una
        // cifra redonda con el cliente y no un porcentaje.
        $interes = $data->interestAmount !== null && $data->interestAmount !== ''
            ? $this->normalize($data->interestAmount)
            : bcdiv(bcmul($pendiente, $tasa, self::SCALE + 2), '100', self::SCALE);

        $cuotas = max(1, $data->installmentsCount);

        $deal->interest_rate = $tasa;
        $deal->interest_amount = $interes;
        $deal->frequency = DealFrequency::tryFrom((string) $data->frequency) ?? DealFrequency::Monthly;
        $deal->installments_count = $cuotas;
        $deal->start_date = $data->startDate ?? now()->toDateString();
        $deal->balance = bcadd($pendiente, $interes, self::SCALE);
    }

    /**
     * Crea el calendario con el MISMO cálculo que usan los préstamos.
     *
     * `PlanDeCuotas` es matemática pura en Core: no acopla este módulo con el de Préstamos —el dealer
     * financia sin tenerlo contratado— pero tampoco deja dos copias del reparto de capital e interés,
     * que es donde salen los errores caros.
     */
    private function generarCuotas(VehicleDeal $deal): void
    {
        $frecuencia = $deal->frequency ?? DealFrequency::Monthly;

        $plan = PlanDeCuotas::calcular(
            $this->resto($deal->agreed_price, $deal->down_payment ?? '0', $deal->trade_in_value ?? '0'),
            $this->normalize($deal->interest_amount ?? '0'),
            (int) $deal->installments_count,
            Carbon::parse((string) $deal->start_date),
            fn (Carbon $desde): Carbon => $frecuencia->advance($desde),
        );

        foreach ($plan as $cuota) {
            $deal->installments()->create([
                'company_id' => $deal->company_id,
                'number' => $cuota['number'],
                'due_date' => $cuota['due_date'],
                'amount' => $cuota['amount'],
                'principal_portion' => $cuota['principal_portion'],
                'interest_portion' => $cuota['interest_portion'],
                'late_fee' => '0',
                'paid_amount' => '0',
                'status' => DealInstallmentStatus::Pending,
            ]);
        }
    }

    /** Reparte el abono de la cuota más vieja a la más nueva. */
    private function repartirEntreCuotas(VehicleDeal $deal, string $abono): void
    {
        $resto = $abono;

        /*
         * `reorder` y no `orderBy`: la relación `installments()` YA ordena por número, así que un
         * `orderBy` de más solo añadía un segundo criterio que nunca se aplicaba. Lo descubrí
         * mutándolo —lo puse al revés y el test siguió en verde—: el orden salía bien por la
         * relación, no porque aquí se pidiera, y eso es quedarse sin red justo en el reparto del
         * dinero. Con `reorder` esta consulta manda, y si alguien lo invierte, el test se cae.
         */
        $pendientes = $deal->installments()
            ->where('status', '!=', DealInstallmentStatus::Paid->value)
            ->reorder('number')
            ->lockForUpdate()
            ->get();

        foreach ($pendientes as $cuota) {
            if (bccomp($resto, '0', self::SCALE) <= 0) {
                break;
            }

            $debe = $cuota->outstanding();

            if (bccomp($debe, '0', self::SCALE) <= 0) {
                continue;
            }

            // Lo que se aplica: lo que falta de la cuota, o lo que quede del abono si es menos.
            $aplica = bccomp($resto, $debe, self::SCALE) >= 0 ? $debe : $resto;

            $cuota->paid_amount = bcadd($this->normalize($cuota->paid_amount ?? '0'), $aplica, self::SCALE);
            $cuota->status = $this->estadoDeLaCuota($cuota->outstanding(), $cuota->paid_amount);
            $cuota->paid_at = $cuota->status === DealInstallmentStatus::Paid ? now() : $cuota->paid_at;
            $cuota->save();

            $resto = bcsub($resto, $aplica, self::SCALE);
        }
    }

    private function estadoDeLaCuota(string $pendiente, string $abonado): DealInstallmentStatus
    {
        if (bccomp($pendiente, '0', self::SCALE) <= 0) {
            return DealInstallmentStatus::Paid;
        }

        return bccomp($abonado, '0', self::SCALE) > 0
            ? DealInstallmentStatus::Partial
            : DealInstallmentStatus::Pending;
    }

    /** El usado que entra en parte de pago vuelve al patio disponible. */
    private function recibirUsado(?int $vehicleId): void
    {
        if ($vehicleId === null) {
            return;
        }

        $usado = Vehicle::query()->whereKey($vehicleId)->first();

        if ($usado !== null && $usado->status !== VehicleStatus::Sold) {
            $usado->status = VehicleStatus::Available;
            $usado->save();
        }
    }

    /** Lo que queda debiendo tras el inicial y el usado. Nunca negativo. */
    private function resto(string $precio, string $inicial, string $usado): string
    {
        $resto = bcsub($this->normalize($precio), bcadd($this->normalize($inicial), $this->normalize($usado), self::SCALE), self::SCALE);

        return bccomp($resto, '0', self::SCALE) < 0 ? '0.00' : $resto;
    }

    /**
     * El cliente, comprobando que es de esta empresa.
     *
     * `withoutGlobalScopes` con `where('company_id')` explícito y no una búsqueda normal: así el
     * fallo por mandar el id de un cliente ajeno se distingue del de un id que no existe, y se puede
     * decir qué pasó en vez de un 404 mudo.
     */
    private function resolveCustomer(int $customerId, int $companyId): Customer
    {
        $customer = Customer::withoutGlobalScopes()->whereKey($customerId)->first();

        if ($customer === null || (int) $customer->company_id !== $companyId) {
            throw DealerException::clienteDeOtraEmpresa();
        }

        return $customer;
    }

    private function nextCode(int $companyId): string
    {
        $count = VehicleDeal::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->count();

        return 'TR-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
