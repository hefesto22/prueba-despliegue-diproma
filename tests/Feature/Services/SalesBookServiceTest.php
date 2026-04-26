<?php

namespace Tests\Feature\Services;

use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Services\FiscalBooks\SalesBookEntry;
use App\Services\FiscalBooks\SalesBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Cubre el dominio del Libro de Ventas SAR:
 *   - Construcción de entries (normalización Invoice + CreditNote)
 *   - Cálculo del resumen (exclusión de anuladas)
 *   - Filtro por establishment_id
 *   - Validación de período
 *
 * Patrón de setup: inyectamos $matriz en cada factura/nota para evitar que
 * Establishment::factory() encadene una NUEVA CompanySetting — same tactic
 * as FiscalPeriodServiceTest.
 */
class SalesBookServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalesBookService $service;

    private CompanySetting $company;

    private Establishment $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create([
            'fiscal_period_start' => '2026-01-01',
        ]);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->service = app(SalesBookService::class);
    }

    /** Helper: factura en el período 2026-04 con montos explícitos */
    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'establishment_id' => $this->matriz->id,
            'invoice_date'     => '2026-04-15',
            'subtotal'         => 1000.00,
            'taxable_total'    => 1000.00,
            'exempt_total'     => 0,
            'isv'              => 150.00,
            'total'            => 1150.00,
            'is_void'          => false,
        ], $overrides));
    }

    /**
     * Helper: nota de crédito en el período 2026-04 ligada a $invoice.
     *
     * Usa el state ->forInvoice() del CreditNoteFactory para copiar snapshots
     * de la Invoice real SIN crear una Invoice fantasma en DB.
     */
    private function makeCreditNote(Invoice $invoice, array $overrides = []): CreditNote
    {
        return CreditNote::factory()
            ->forInvoice($invoice)
            ->create(array_merge([
                'credit_note_date' => '2026-04-20',
                'subtotal'         => 500.00,
                'taxable_total'    => 500.00,
                'exempt_total'     => 0,
                'isv'              => 75.00,
                'total'            => 575.00,
                'is_void'          => false,
            ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════════
    // Construcción básica
    // ═══════════════════════════════════════════════════════════════

    public function test_retorna_libro_vacio_si_no_hay_documentos_en_el_periodo(): void
    {
        $book = $this->service->build(2026, 4);

        $this->assertCount(0, $book->entries);
        $this->assertSame(0, $book->summary->facturasEmitidasCount);
        $this->assertSame(0, $book->summary->notasCreditoEmitidasCount);
        $this->assertSame(0.0, $book->summary->ventaNeta());
    }

    public function test_incluye_solo_facturas_del_periodo_objetivo(): void
    {
        $this->makeInvoice(['invoice_date' => '2026-04-10']);
        $this->makeInvoice(['invoice_date' => '2026-04-28']);
        $this->makeInvoice(['invoice_date' => '2026-03-30']); // fuera
        $this->makeInvoice(['invoice_date' => '2026-05-01']); // fuera

        $book = $this->service->build(2026, 4);

        $this->assertCount(2, $book->entries);
        $this->assertSame(2, $book->summary->facturasEmitidasCount);
    }

    public function test_incluye_notas_credito_del_periodo(): void
    {
        $invoice = $this->makeInvoice();
        $this->makeCreditNote($invoice, ['credit_note_date' => '2026-04-20']);
        $this->makeCreditNote($invoice, ['credit_note_date' => '2026-03-15']); // fuera

        $book = $this->service->build(2026, 4);

        $this->assertSame(1, $book->summary->facturasEmitidasCount);
        $this->assertSame(1, $book->summary->notasCreditoEmitidasCount);
        $this->assertCount(2, $book->entries); // factura + NC
    }

    // ═══════════════════════════════════════════════════════════════
    // Normalización de entries
    // ═══════════════════════════════════════════════════════════════

    public function test_normaliza_rtn_null_a_consumidor_final(): void
    {
        $this->makeInvoice(['customer_rtn' => null]);

        $book = $this->service->build(2026, 4);

        /** @var SalesBookEntry $entry */
        $entry = $book->entries->first();
        $this->assertSame('0000000000000', $entry->rtnReceptor);
    }

    public function test_mantiene_rtn_receptor_cuando_existe(): void
    {
        $this->makeInvoice(['customer_rtn' => '08011985123456']);

        $book = $this->service->build(2026, 4);

        /** @var SalesBookEntry $entry */
        $entry = $book->entries->first();
        $this->assertSame('08011985123456', $entry->rtnReceptor);
    }

    public function test_entries_se_ordenan_cronologicamente(): void
    {
        $this->makeInvoice(['invoice_date' => '2026-04-20']);
        $this->makeInvoice(['invoice_date' => '2026-04-05']);
        $this->makeInvoice(['invoice_date' => '2026-04-15']);

        $book = $this->service->build(2026, 4);

        $fechas = $book->entries->map(fn ($e) => $e->fecha->toDateString())->all();
        $this->assertSame(['2026-04-05', '2026-04-15', '2026-04-20'], $fechas);
    }

    public function test_factura_y_nota_credito_del_mismo_dia_ordenan_por_tipo(): void
    {
        $invoice = $this->makeInvoice(['invoice_date' => '2026-04-15']);
        $this->makeCreditNote($invoice, ['credit_note_date' => '2026-04-15']);

        $book = $this->service->build(2026, 4);

        $tipos = $book->entries->map(fn ($e) => $e->tipoDocumento)->all();
        $this->assertSame(['01', '03'], $tipos); // factura antes que NC el mismo día
    }

    // ═══════════════════════════════════════════════════════════════
    // Anulaciones: aparecen en detalle pero NO suman en totales
    // ═══════════════════════════════════════════════════════════════

    public function test_factura_anulada_aparece_en_detalle_pero_no_suma_en_totales(): void
    {
        $this->makeInvoice();              // vigente: 1150
        $this->makeInvoice(['is_void' => true]); // anulada

        $book = $this->service->build(2026, 4);

        $this->assertCount(2, $book->entries); // ambas en detalle
        $this->assertSame(2, $book->summary->facturasEmitidasCount);
        $this->assertSame(1, $book->summary->facturasVigentesCount);
        $this->assertSame(1, $book->summary->facturasAnuladasCount);
        $this->assertSame(1150.00, $book->summary->facturasTotal); // solo la vigente
        $this->assertSame(150.00, $book->summary->facturasIsv);
    }

    public function test_nota_credito_anulada_no_suma_en_totales(): void
    {
        $invoice = $this->makeInvoice();
        $this->makeCreditNote($invoice);                         // vigente
        $this->makeCreditNote($invoice, ['is_void' => true]);    // anulada

        $book = $this->service->build(2026, 4);

        $this->assertSame(2, $book->summary->notasCreditoEmitidasCount);
        $this->assertSame(1, $book->summary->notasCreditoVigentesCount);
        $this->assertSame(1, $book->summary->notasCreditoAnuladasCount);
        $this->assertSame(575.00, $book->summary->notasCreditoTotal);
    }

    public function test_entry_anulada_marca_su_estado(): void
    {
        $this->makeInvoice(['is_void' => true]);

        $book = $this->service->build(2026, 4);

        $entry = $book->entries->first();
        $this->assertTrue($entry->anulada);
        $this->assertFalse($entry->cuentaEnTotales());
        $this->assertSame('Anulada', $entry->estadoLabel());
    }

    // ═══════════════════════════════════════════════════════════════
    // Cálculo de netos (resumen fiscal)
    // ═══════════════════════════════════════════════════════════════

    public function test_venta_neta_resta_notas_credito_de_facturas(): void
    {
        $invoice = $this->makeInvoice(); // total 1150
        $this->makeCreditNote($invoice); // total 575

        $book = $this->service->build(2026, 4);

        $this->assertSame(1150.00, $book->summary->facturasTotal);
        $this->assertSame(575.00, $book->summary->notasCreditoTotal);
        $this->assertSame(575.00, $book->summary->ventaNeta());
    }

    public function test_isv_neto_resta_isv_de_notas_credito(): void
    {
        $invoice = $this->makeInvoice(); // ISV 150
        $this->makeCreditNote($invoice); // ISV 75

        $book = $this->service->build(2026, 4);

        $this->assertSame(75.00, $book->summary->isvNeto());
    }

    public function test_gravado_y_exento_netos_restan_correctamente(): void
    {
        $invoice = $this->makeInvoice([
            'taxable_total' => 1000.00,
            'exempt_total'  => 200.00,
            'subtotal'      => 1200.00,
            'isv'           => 150.00,
            'total'         => 1350.00,
        ]);
        $this->makeCreditNote($invoice, [
            'taxable_total' => 300.00,
            'exempt_total'  => 50.00,
            'subtotal'      => 350.00,
            'isv'           => 45.00,
            'total'         => 395.00,
        ]);

        $book = $this->service->build(2026, 4);

        $this->assertSame(700.00, $book->summary->gravadoNeto());
        $this->assertSame(150.00, $book->summary->exentoNeto());
    }

    // ═══════════════════════════════════════════════════════════════
    // Filtro por establishment_id
    // ═══════════════════════════════════════════════════════════════

    public function test_filtro_por_establishment_restringe_resultados(): void
    {
        $sucursal = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create();

        $this->makeInvoice(); // matriz
        $this->makeInvoice(['establishment_id' => $sucursal->id]);

        $librComp = $this->service->build(2026, 4);
        $librMatriz   = $this->service->build(2026, 4, $this->matriz->id);
        $librSucursal = $this->service->build(2026, 4, $sucursal->id);

        $this->assertSame(2, $librComp->summary->facturasEmitidasCount);
        $this->assertSame(1, $librMatriz->summary->facturasEmitidasCount);
        $this->assertSame(1, $librSucursal->summary->facturasEmitidasCount);
    }

    /**
     * Aislamiento de CONTENIDO — el count correcto no es suficiente: si el
     * filtro se aplicara invertido (intercambiando IDs), el count podría
     * coincidir pero los documentos serían los de la otra sucursal. Este
     * test valida que los números retornados son EXACTAMENTE los de la
     * sucursal solicitada.
     *
     * Cubre además cross-document-type leak: el Libro de Ventas lee DOS
     * modelos (Invoice y CreditNote). Un bug que agregue filtro a uno pero
     * no al otro solo se detecta cuando hay ambos tipos en cada sucursal.
     */
    public function test_filtro_por_establishment_no_incluye_documentos_de_otra_sucursal(): void
    {
        $sucursal = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create();

        // Matriz: 2 facturas + 1 NC sobre la primera
        $facturaMatriz1 = $this->makeInvoice(['invoice_number' => '000-001-01-00000001']);
        $this->makeInvoice(['invoice_number' => '000-001-01-00000002']);
        $this->makeCreditNote($facturaMatriz1, [
            'establishment_id'     => $this->matriz->id,
            'credit_note_number'   => '000-001-03-00000001',
        ]);

        // Sucursal: 2 facturas + 1 NC sobre la primera
        $facturaSuc1 = $this->makeInvoice([
            'establishment_id' => $sucursal->id,
            'invoice_number'   => '000-002-01-00000001',
        ]);
        $this->makeInvoice([
            'establishment_id' => $sucursal->id,
            'invoice_number'   => '000-002-01-00000002',
        ]);
        $this->makeCreditNote($facturaSuc1, [
            'establishment_id'   => $sucursal->id,
            'credit_note_number' => '000-002-03-00000001',
        ]);

        // Filtro por sucursal → SOLO los 3 de sucursal
        $libroSucursal = $this->service->build(2026, 4, $sucursal->id);
        $numerosSucursal = $libroSucursal->entries->pluck('numero')->sort()->values()->all();

        $this->assertSame(
            ['000-002-01-00000001', '000-002-01-00000002', '000-002-03-00000001'],
            $numerosSucursal,
            'El libro de Sucursal incluye documentos que no le pertenecen (cross-leak).',
        );

        // Filtro por matriz → SOLO los 3 de matriz
        $libroMatriz = $this->service->build(2026, 4, $this->matriz->id);
        $numerosMatriz = $libroMatriz->entries->pluck('numero')->sort()->values()->all();

        $this->assertSame(
            ['000-001-01-00000001', '000-001-01-00000002', '000-001-03-00000001'],
            $numerosMatriz,
            'El libro de Matriz incluye documentos que no le pertenecen (cross-leak).',
        );

        // Sin filtro → los 6 juntos
        $libroComp = $this->service->build(2026, 4);
        $this->assertSame(6, $libroComp->entries->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // Validación de período
    // ═══════════════════════════════════════════════════════════════

    public function test_lanza_excepcion_para_mes_fuera_de_rango(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->build(2026, 13);
    }

    public function test_lanza_excepcion_para_mes_cero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->build(2026, 0);
    }

    public function test_lanza_excepcion_para_anio_fuera_de_rango(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->build(1999, 4);
    }

    // ═══════════════════════════════════════════════════════════════
    // Metadatos del resumen
    // ═══════════════════════════════════════════════════════════════

    public function test_period_label_y_slug_formateados(): void
    {
        $book = $this->service->build(2026, 4);

        $this->assertSame('Abril 2026', $book->summary->periodLabel());
        $this->assertSame('2026-04', $book->summary->periodSlug());
    }
}
