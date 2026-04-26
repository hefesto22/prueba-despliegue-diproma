<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Purchase>
 *
 * Los campos fiscales SAR (supplier_invoice_number, supplier_cai, document_type)
 * se generan con formato válido para que los tests del Libro de Compras
 * puedan asumir datos coherentes. `taxable_total`/`exempt_total` quedan en 0
 * y se calculan por `PurchaseTotalsCalculator` al confirmar (misma regla que
 * `subtotal`/`isv`/`total`).
 */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-30 days', 'now');

        return [
            // Reutiliza la matriz existente (evita pollution del cache de CompanySetting).
            'establishment_id'        => fn () => Establishment::main()->value('id')
                ?? Establishment::factory()->main()->create()->id,
            'supplier_id'             => Supplier::factory(),
            'supplier_invoice_number' => $this->nextSupplierInvoiceNumber(),
            'supplier_cai'            => $this->faker->regexify('[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}'),
            'document_type'           => SupplierDocumentType::Factura,
            'date'                    => $date,
            'status'                  => PurchaseStatus::Borrador,
            'payment_status'          => PaymentStatus::Pendiente,
            'subtotal'                => 0,
            'taxable_total'           => 0,
            'exempt_total'            => 0,
            'isv'                     => 0,
            'total'                   => 0,
            'credit_days'             => 0,
            'notes'                   => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Compra en una sucursal específica — útil para tests multi-sucursal
     * del Libro de Compras por establecimiento.
     */
    public function forEstablishment(Establishment $establishment): static
    {
        return $this->state(fn () => ['establishment_id' => $establishment->id]);
    }

    /**
     * Compra confirmada.
     */
    public function confirmada(): static
    {
        return $this->state(fn () => ['status' => PurchaseStatus::Confirmada]);
    }

    /**
     * Compra anulada.
     */
    public function anulada(): static
    {
        return $this->state(fn () => ['status' => PurchaseStatus::Anulada]);
    }

    /**
     * Compra a crédito.
     */
    public function aCredito(int $days = 30): static
    {
        return $this->state(fn () => ['credit_days' => $days]);
    }

    /**
     * Compra con proveedor específico.
     *
     * Nota: NO heredamos `credit_days` del proveedor porque el módulo de Cuentas
     * por Pagar (crédito a proveedores) está pendiente de implementación. Toda
     * compra hoy es al contado. Si un test necesita simular crédito explícitamente
     * debe encadenar `->aCredito($days)` después de `->fromSupplier($supplier)`.
     * Cuando CxP se implemente y el form vuelva a heredar credit_days del
     * proveedor, restaurar la línea original `'credit_days' => $supplier->credit_days`.
     */
    public function fromSupplier(Supplier $supplier): static
    {
        return $this->state(fn () => [
            'supplier_id' => $supplier->id,
        ]);
    }

    /**
     * Totales explícitos — útil para tests del Libro de Compras que necesitan
     * cuadrar montos sin pasar por el Calculator (que exige items reales).
     */
    public function withTotals(float $taxable, float $exempt = 0.0): static
    {
        return $this->state(function () use ($taxable, $exempt) {
            $isv      = round($taxable * 0.15, 2);
            $subtotal = round($taxable + $exempt, 2);

            return [
                'subtotal'      => $subtotal,
                'taxable_total' => $taxable,
                'exempt_total'  => $exempt,
                'isv'           => $isv,
                'total'         => round($subtotal + $isv, 2),
            ];
        });
    }

    /**
     * Correlativo SAR del proveedor — formato XXX-XXX-01-XXXXXXXX generado
     * sin tocar ningún rango CAI real. El número interno `purchase_number`
     * se auto-genera en `Purchase::creating`.
     */
    private function nextSupplierInvoiceNumber(): string
    {
        static $seq = 0;
        $seq++;

        return '001-001-01-' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
    }
}
