<?php

namespace Database\Factories;

use App\Enums\CreditNoteReason;
use App\Models\CaiRange;
use App\Models\CreditNote;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditNote>
 *
 * Factory de conveniencia para tests. El flujo REAL de emisión en producción
 * es CreditNoteService::generateFromInvoice() — esa es la única ruta que
 * calcula correctamente totales, valida saldo acumulativo y dispara eventos.
 *
 * Diseño de snapshots:
 *   - `invoice_id` se define como `Invoice::factory()` LAZY. Laravel lo
 *     resuelve al persistir, y solo si nadie lo sobreescribe. Cuando un test
 *     usa ->forInvoice($existing), el state sobreescribe invoice_id ANTES de
 *     que el lazy se dispare — no queda Invoice fantasma en DB.
 *   - Los demás snapshots (company, customer, CAI, original_invoice_*) se
 *     generan con valores propios del factory (Faker/constantes) en lugar
 *     de instanciar una Invoice real. Si se necesita coherencia con una
 *     Invoice específica, usar el state ->forInvoice($invoice).
 *
 * Uso típico:
 *   CreditNote::factory()->create();                         // NC huérfana
 *   CreditNote::factory()->forInvoice($invoice)->create();   // NC ligada a una Invoice real
 *   CreditNote::factory()->voided()->create();
 */
class CreditNoteFactory extends Factory
{
    public function definition(): array
    {
        $cai      = $this->faker->regexify('[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}');
        $subtotal = 500.00;
        $isv      = round($subtotal * 0.15, 2);
        $total    = round($subtotal + $isv, 2);

        return [
            // LAZY: solo se persiste si nadie sobreescribe invoice_id
            // (p.ej. cuando se usa ->forInvoice($existing)).
            'invoice_id'              => Invoice::factory(),
            'cai_range_id'            => CaiRange::factory(),
            'establishment_id'        => Establishment::factory(),

            'credit_note_number'      => $this->nextNumber(),
            'cai'                     => $cai,
            'emission_point'          => '001',
            'credit_note_date'        => now()->toDateString(),
            'cai_expiration_date'     => now()->addMonths(6)->toDateString(),

            'reason'                  => CreditNoteReason::DevolucionFisica,
            'reason_notes'            => null,

            // Snapshot emisor — constantes de test para determinismo
            'company_name'            => 'Empresa Test S. de R.L.',
            'company_rtn'             => '08019999000000',
            'company_address'         => 'Col. Test, Tegucigalpa, Honduras',
            'company_phone'           => '+504 2222-0000',
            'company_email'           => 'facturacion@empresa.test',

            // Snapshot receptor
            'customer_name'           => $this->faker->name(),
            'customer_rtn'            => null,

            // Snapshot factura origen — autónomo pero formalmente válido.
            // Cuando se use ->forInvoice($invoice), estos campos se
            // sobreescriben con los valores reales de la factura ligada.
            'original_invoice_number' => '001-001-01-' . str_pad(
                (string) $this->faker->numberBetween(1, 99_999_999),
                8,
                '0',
                STR_PAD_LEFT,
            ),
            'original_invoice_cai'    => $cai,
            'original_invoice_date'   => now()->subDay()->toDateString(),

            // Totales por defecto: NC "pequeña" de 500 HNL + ISV 15%
            'subtotal'                => $subtotal,
            'exempt_total'            => 0,
            'taxable_total'           => $subtotal,
            'isv'                     => $isv,
            'total'                   => $total,

            'is_void'                 => false,
            'without_cai'             => false,
            'pdf_path'                => null,
            'integrity_hash'          => null,
            'emitted_at'              => now(),
            'created_by'              => User::factory(),
        ];
    }

    // ─── States ──────────────────────────────────────────────

    /**
     * Ligar la NC a una Invoice real existente copiando todos sus snapshots.
     *
     * Resuelve la trampa que existía antes: `definition()` creaba una
     * Invoice interna solo para extraer snapshots y esa factura quedaba
     * huérfana en DB cuando el caller realmente solo quería una NC ligada
     * a su propia Invoice. Ahora los snapshots vienen del $invoice pasado,
     * y `invoice_id` se sobreescribe antes de que el lazy se materialice.
     */
    public function forInvoice(Invoice $invoice): self
    {
        return $this->state(fn () => [
            'invoice_id'              => $invoice->id,
            'cai_range_id'            => $invoice->cai_range_id,
            'establishment_id'        => $invoice->establishment_id,

            'cai'                     => $invoice->cai,
            'emission_point'          => $invoice->emission_point,
            'cai_expiration_date'     => $invoice->cai_expiration_date,

            'company_name'            => $invoice->company_name,
            'company_rtn'             => $invoice->company_rtn,
            'company_address'         => $invoice->company_address,
            'company_phone'           => $invoice->company_phone,
            'company_email'           => $invoice->company_email,

            'customer_name'           => $invoice->customer_name,
            'customer_rtn'            => $invoice->customer_rtn,

            'original_invoice_number' => $invoice->invoice_number,
            'original_invoice_cai'    => $invoice->cai,
            'original_invoice_date'   => $invoice->invoice_date,

            'without_cai'             => (bool) $invoice->without_cai,
            'created_by'              => $invoice->created_by ?? User::factory(),
        ]);
    }

    public function voided(): self
    {
        return $this->state(fn () => ['is_void' => true]);
    }

    public function withoutCai(): self
    {
        return $this->state(fn () => [
            'without_cai' => true,
            'cai'         => null,
        ]);
    }

    public function forReason(CreditNoteReason $reason, ?string $notes = null): self
    {
        return $this->state(fn () => [
            'reason'       => $reason,
            'reason_notes' => $notes,
        ]);
    }

    /**
     * Totales explícitos — útil para tests que necesitan cuadrar montos
     * (p. ej. Libro de Ventas, conciliaciones, cálculos de netos).
     */
    public function withTotals(float $subtotal, float $exempt = 0.0): self
    {
        return $this->state(function () use ($subtotal, $exempt) {
            $isv   = round($subtotal * 0.15, 2);
            $total = round($subtotal + $exempt + $isv, 2);

            return [
                'subtotal'      => $subtotal,
                'taxable_total' => $subtotal,
                'exempt_total'  => $exempt,
                'isv'           => $isv,
                'total'         => $total,
            ];
        });
    }

    // ─── Helpers internos ────────────────────────────────────

    /**
     * Correlativo de test. Formato SAR (XXX-XXX-03-XXXXXXXX) pero generado
     * sin avanzar ningún CAI real — los tests que necesiten correlativos
     * verdaderos deben ir vía el resolver.
     */
    private function nextNumber(): string
    {
        static $seq = 0;
        $seq++;

        return '001-001-03-' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
    }
}
