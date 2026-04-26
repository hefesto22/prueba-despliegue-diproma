<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Purchases;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Purchases\Exceptions\TransaccionRequeridaException;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests del generador de correlativos para Recibo Interno.
 *
 * Cubre:
 *   - Formato RI-YYYYMMDD-NNNN con padding y fecha correcta
 *   - Secuencia diaria que reinicia en 0001 cada día
 *   - Exigencia de transacción (LogicException fuera de DB::transaction)
 *   - Respeto del lock serializador (valor observable: el servicio obtiene el
 *     lock sin error cuando se ejecuta linealmente dentro de transacción)
 *   - Secuencia robusta ante soft-deletes (no reasigna NNNN ya consumido)
 */
class InternalReceiptNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private InternalReceiptNumberGenerator $generator;

    private Supplier $generico;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        // El proveedor genérico lo inserta la migración de RI. En tests
        // RefreshDatabase corre las migraciones, así que debe existir.
        // Si no existe, el propio helper forInternalReceipts() explota —
        // fail-fast validando la invariante del módulo.
        $this->generico = Supplier::forInternalReceipts();

        $this->matriz = Establishment::factory()->main()->create();

        $this->generator = app(InternalReceiptNumberGenerator::class);
    }

    // ─── Formato ──────────────────────────────────────────────

    public function test_primer_correlativo_del_dia_es_nnnn_igual_a_0001(): void
    {
        $fecha = CarbonImmutable::parse('2026-04-19');

        $numero = DB::transaction(fn () => $this->generator->next($fecha));

        $this->assertSame('RI-20260419-0001', $numero);
    }

    public function test_segundo_correlativo_del_dia_incrementa_a_0002(): void
    {
        $fecha = CarbonImmutable::parse('2026-04-19');

        $primero = DB::transaction(fn () => $this->generator->next($fecha));
        $this->persistPurchaseConNumero($primero, $fecha);

        $segundo = DB::transaction(fn () => $this->generator->next($fecha));

        $this->assertSame('RI-20260419-0001', $primero);
        $this->assertSame('RI-20260419-0002', $segundo);
    }

    public function test_formato_incluye_fecha_del_parametro_no_fecha_de_hoy(): void
    {
        // Ejercitamos una fecha arbitraria pasada para comprobar que el
        // segmento YYYYMMDD refleja el argumento, no now().
        $fecha = CarbonImmutable::parse('2025-12-31');

        $numero = DB::transaction(fn () => $this->generator->next($fecha));

        $this->assertSame('RI-20251231-0001', $numero);
    }

    // ─── Reinicio diario ──────────────────────────────────────

    public function test_correlativo_reinicia_al_cambiar_el_dia(): void
    {
        $dia1 = CarbonImmutable::parse('2026-04-19');
        $dia2 = CarbonImmutable::parse('2026-04-20');

        // Día 1: 0001
        $n1 = DB::transaction(fn () => $this->generator->next($dia1));
        $this->persistPurchaseConNumero($n1, $dia1);

        // Día 1: 0002
        $n2 = DB::transaction(fn () => $this->generator->next($dia1));
        $this->persistPurchaseConNumero($n2, $dia1);

        // Día 2: reinicia a 0001
        $n3 = DB::transaction(fn () => $this->generator->next($dia2));

        $this->assertSame('RI-20260419-0001', $n1);
        $this->assertSame('RI-20260419-0002', $n2);
        $this->assertSame('RI-20260420-0001', $n3);
    }

    // ─── Contrato de transacción ──────────────────────────────

    public function test_lanza_excepcion_si_se_llama_fuera_de_una_transaccion(): void
    {
        // RefreshDatabase envuelve cada test en una transacción — si no la
        // cerramos, DB::transactionLevel() siempre retorna >=1 y el guard
        // del generador nunca se dispara. Rollback manual + reapertura en
        // finally para que tearDown de RefreshDatabase no explote.
        DB::rollBack();

        try {
            $this->expectException(TransaccionRequeridaException::class);

            $this->generator->next(CarbonImmutable::now());
        } finally {
            DB::beginTransaction();
        }
    }

    // ─── Soft-delete resilience ───────────────────────────────

    public function test_no_reasigna_correlativo_de_un_ri_soft_deleted(): void
    {
        $fecha = CarbonImmutable::parse('2026-04-19');

        // Primer RI: consumió NNNN=0001
        $primero = DB::transaction(fn () => $this->generator->next($fecha));
        $purchase = $this->persistPurchaseConNumero($primero, $fecha);

        // Soft-delete: el Purchase desaparece de las queries normales
        $purchase->delete();
        $this->assertSoftDeleted($purchase);

        // El siguiente correlativo debe ser 0002, NO 0001 — reasignar sería
        // un conflicto de auditoría (dos documentos distintos con mismo folio).
        $segundo = DB::transaction(fn () => $this->generator->next($fecha));

        $this->assertSame('RI-20260419-0001', $primero);
        $this->assertSame('RI-20260419-0002', $segundo);
    }

    // ─── Aislamiento de tipos ─────────────────────────────────

    public function test_ignora_facturas_con_formato_parecido_al_calcular_secuencia(): void
    {
        $fecha = CarbonImmutable::parse('2026-04-19');

        // Creamos una Factura cuyo supplier_invoice_number NO comienza con
        // el prefijo RI-YYYYMMDD- — no debe afectar el cálculo del correlativo RI.
        Purchase::factory()
            ->confirmada()
            ->create([
                'establishment_id'        => $this->matriz->id,
                'date'                    => $fecha,
                'document_type'           => SupplierDocumentType::Factura,
                'supplier_invoice_number' => '001-001-01-99999999',
            ]);

        $numero = DB::transaction(fn () => $this->generator->next($fecha));

        $this->assertSame('RI-20260419-0001', $numero);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Persiste un Purchase con el correlativo RI recién generado para que el
     * siguiente `next()` lo vea y calcule NNNN+1. Emula lo que haría
     * CreatePurchase::handleRecordCreation en producción.
     */
    private function persistPurchaseConNumero(string $numero, CarbonImmutable $fecha): Purchase
    {
        return Purchase::factory()
            ->confirmada()
            ->create([
                'establishment_id'        => $this->matriz->id,
                'supplier_id'             => $this->generico->id,
                'document_type'           => SupplierDocumentType::ReciboInterno,
                'supplier_invoice_number' => $numero,
                'supplier_cai'            => null,
                'date'                    => $fecha,
            ]);
    }
}
