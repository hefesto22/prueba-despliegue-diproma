<?php

namespace Database\Factories;

use App\Models\CaiRange;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 *
 * Factory de conveniencia para tests. El flujo REAL de emisión en producción
 * es InvoiceService::generateFromSale() — esa es la única ruta que resuelve
 * correlativo SAR, calcula desglose fiscal y sella el hash de integridad.
 *
 * Esta factory monta una factura "ya emitida" con datos consistentes
 * (correlativo formateado, emitted_at=now) para tests que no necesiten
 * ejercitar todo el servicio.
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 100, 5000);
        $isv      = round($subtotal * 0.15, 2);
        $total    = round($subtotal + $isv, 2);

        return [
            'sale_id'             => Sale::factory()->completada(),
            'cai_range_id'        => CaiRange::factory(),
            'establishment_id'    => Establishment::factory(),

            'invoice_number'      => $this->nextNumber(),
            'cai'                 => $this->faker->regexify('[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}'),
            'emission_point'      => '001',
            'invoice_date'        => now()->toDateString(),
            'cai_expiration_date' => now()->addMonths(6)->toDateString(),

            // Snapshot emisor
            'company_name'        => 'Empresa Test S. de R.L.',
            'company_rtn'         => '08019999000000',
            'company_address'     => 'Col. Test, Tegucigalpa, Honduras',
            'company_phone'       => '+504 2222-0000',
            'company_email'       => 'facturacion@empresa.test',

            // Snapshot receptor
            'customer_name'       => $this->faker->name(),
            'customer_rtn'        => null,

            // Totales
            'subtotal'            => $subtotal,
            'exempt_total'        => 0,
            'taxable_total'       => $subtotal,
            'isv'                 => $isv,
            'discount'            => 0,
            'total'               => $total,

            'is_void'             => false,
            'without_cai'         => false,
            'pdf_path'            => null,
            'integrity_hash'      => null,
            'emitted_at'          => now(),
            'created_by'          => User::factory(),
        ];
    }

    // ─── States ──────────────────────────────────────────────

    public function voided(): self
    {
        return $this->state(fn () => ['is_void' => true]);
    }

    public function withoutCai(): self
    {
        return $this->state(fn () => [
            'without_cai'         => true,
            'cai'                 => null,
            'cai_range_id'        => null,
            'cai_expiration_date' => null,
        ]);
    }

    public function withTotal(float $subtotal): self
    {
        return $this->state(function () use ($subtotal) {
            $isv   = round($subtotal * 0.15, 2);
            $total = round($subtotal + $isv, 2);

            return [
                'subtotal'      => $subtotal,
                'taxable_total' => $subtotal,
                'exempt_total'  => 0,
                'isv'           => $isv,
                'total'         => $total,
            ];
        });
    }

    // ─── Helpers internos ────────────────────────────────────

    /**
     * Correlativo de test único por proceso (no toca ningún CAI real).
     * Los tests que necesiten correlativos verdaderos deben ir vía resolver.
     */
    private function nextNumber(): string
    {
        static $seq = 0;
        $seq++;

        return '001-001-01-' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
    }
}
