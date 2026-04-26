<?php

namespace Database\Factories;

use App\Enums\IsvRetentionType;
use App\Models\Establishment;
use App\Models\IsvRetentionReceived;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IsvRetentionReceived>
 *
 * Genera retenciones ISV válidas para los tres tipos SIISAR. Por defecto crea
 * una retención de tarjetas (el caso más frecuente en una operación retail
 * como Diproma). States específicos permiten forzar los otros dos casos
 * cuando los tests de declaración ISV los requieran.
 */
class IsvRetentionReceivedFactory extends Factory
{
    protected $model = IsvRetentionReceived::class;

    public function definition(): array
    {
        $now = now();

        return [
            // Por defecto cuelga de la sucursal principal — consistente con
            // purchases/sales factories. Nullable se fuerza con el state sinSucursal().
            'establishment_id' => fn () => Establishment::main()->value('id')
                ?? Establishment::factory()->main()->create()->id,

            'period_year'      => $now->year,
            'period_month'     => $now->month,
            'retention_type'   => IsvRetentionType::TarjetasCreditoDebito,

            'agent_rtn'        => $this->faker->numerify('##############'),
            'agent_name'       => $this->faker->company(),
            'document_number'  => $this->faker->optional(0.7)->bothify('CR-########'),
            'document_path'    => null,

            'amount'           => $this->faker->randomFloat(2, 50, 5000),
            'notes'            => $this->faker->optional(0.2)->sentence(),
        ];
    }

    /**
     * Retención de un período mensual específico.
     */
    public function forPeriod(int $year, int $month): static
    {
        return $this->state(fn () => [
            'period_year'  => $year,
            'period_month' => $month,
        ]);
    }

    /**
     * Forzar el tipo de retención (Tarjetas / Estado / Acuerdo 215-2010).
     */
    public function ofType(IsvRetentionType $type): static
    {
        return $this->state(fn () => ['retention_type' => $type]);
    }

    /**
     * Retención por ventas al Estado (PCM-051-2011).
     */
    public function ventasEstado(): static
    {
        return $this->ofType(IsvRetentionType::VentasEstado);
    }

    /**
     * Retención por gran contribuyente (Acuerdo 215-2010).
     */
    public function acuerdo215(): static
    {
        return $this->ofType(IsvRetentionType::Acuerdo215_2010);
    }

    /**
     * Retención sin establecimiento asignado — útil para tests de
     * compatibilidad con datos pre-F6b.
     */
    public function sinSucursal(): static
    {
        return $this->state(fn () => ['establishment_id' => null]);
    }

    /**
     * Monto explícito — para tests donde la cuadratura contra el libro de
     * ventas o el total de la sección C del 201 importa.
     */
    public function withAmount(float $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    /**
     * Sucursal específica.
     */
    public function forEstablishment(Establishment $establishment): static
    {
        return $this->state(fn () => ['establishment_id' => $establishment->id]);
    }
}
