<?php

namespace Tests\Feature\Services;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\FiscalBooks\PurchaseBookEntry;
use App\Services\FiscalBooks\PurchaseBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Cubre el dominio del Libro de Compras SAR:
 *   - Construcción de entries a partir de Purchase (1 modelo, document_type discriminante)
 *   - Exclusión de borradores (fiscalmente no existen)
 *   - Cálculo del resumen segregado por tipo (Factura/NC/ND)
 *   - Cálculo del crédito fiscal neto (F + ND − NC)
 *   - Anuladas aparecen en detalle pero NO suman en totales
 *   - Validación de período
 *   - Resolución de rtn_receptor desde CompanySetting
 *   - Filtro opcional por establishment_id (F6a.6)
 *
 * Espejo simétrico de SalesBookServiceTest — misma forma de setup y de
 * helpers para mantener coherencia entre ambas suites de libros fiscales.
 */
class PurchaseBookServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseBookService $service;

    private CompanySetting $company;

    private Establishment $matriz;

    private Supplier $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create([
            'rtn' => '08011999123456', // RTN de Diproma (receptor de todas las compras)
        ]);

        // Calentar cache para que CompanySetting::current() retorne esta instancia
        // (evita que firstOrCreate(['id' => 1]) cree un registro diferente si
        // el auto-increment de MySQL avanzó por tests previos).
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        // Matriz por defecto — todas las compras construidas por makePurchase()
        // se anclan a esta sucursal salvo override explícito.
        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        $this->proveedor = Supplier::factory()->create([
            'name' => 'Proveedor Demo S.A.',
            'rtn'  => '05011988654321',
        ]);

        $this->service = app(PurchaseBookService::class);
    }

    /** Helper: compra en el período 2026-04 con montos explícitos y estado Confirmada. */
    private function makePurchase(array $overrides = []): Purchase
    {
        return Purchase::factory()
            ->fromSupplier($this->proveedor)
            ->withTotals(
                taxable: $overrides['taxable_total'] ?? 1000.00,
                exempt:  $overrides['exempt_total']  ?? 0.0,
            )
            ->confirmada()
            ->create(array_merge([
                'date'             => '2026-04-15',
                'document_type'    => SupplierDocumentType::Factura,
                'establishment_id' => $this->matriz->id,
            ], array_diff_key($overrides, array_flip(['taxable_total', 'exempt_total']))));
    }

    // ═══════════════════════════════════════════════════════════════
    // Construcción básica
    // ═══════════════════════════════════════════════════════════════

    public function test_retorna_libro_vacio_si_no_hay_compras_en_el_periodo(): void
    {
        $book = $this->service->build(2026, 4);

        $this->assertCount(0, $book->entries);
        $this->assertSame(0, $book->summary->facturasEmitidasCount);
        $this->assertSame(0, $book->summary->notasCreditoEmitidasCount);
        $this->assertSame(0, $book->summary->notasDebitoEmitidasCount);
        $this->assertSame(0.0, $book->summary->compraNeta());
        $this->assertSame(0.0, $book->summary->creditoFiscalNeto());
    }

    public function test_incluye_solo_compras_del_periodo_objetivo(): void
    {
        $this->makePurchase(['date' => '2026-04-10']);
        $this->makePurchase(['date' => '2026-04-28']);
        $this->makePurchase(['date' => '2026-03-30']); // fuera
        $this->makePurchase(['date' => '2026-05-01']); // fuera

        $book = $this->service->build(2026, 4);

        $this->assertCount(2, $book->entries);
        $this->assertSame(2, $book->summary->facturasEmitidasCount);
    }

    // ═══════════════════════════════════════════════════════════════
    // Exclusión de borradores (diferencia crítica con ventas)
    // ═══════════════════════════════════════════════════════════════

    public function test_borradores_no_aparecen_en_el_libro(): void
    {
        // Confirmada: debe aparecer
        $this->makePurchase();

        // Borrador: NO debe aparecer — fiscalmente no existe
        Purchase::factory()
            ->fromSupplier($this->proveedor)
            ->withTotals(2000.00)
            ->create(['date' => '2026-04-20', 'status' => PurchaseStatus::Borrador]);

        $book = $this->service->build(2026, 4);

        $this->assertCount(1, $book->entries);
        $this->assertSame(1, $book->summary->facturasEmitidasCount);
        $this->assertSame(1000.00, $book->summary->facturasGravado);
    }

    // ═══════════════════════════════════════════════════════════════
    // Exclusión de Recibos Internos (document_type='99')
    // ═══════════════════════════════════════════════════════════════

    /**
     * Los Recibos Internos son compras informales sin CAI — no entran al
     * Libro de Compras SAR, ni siquiera en el detalle. Este test verifica
     * que un RI del período NO aparece en entries ni contribuye al summary.
     *
     * Es un test de aislamiento fiscal: si se escapara un RI al libro, lo que
     * se declara al SAR sería incorrecto (crédito fiscal inflado o RTN "000...").
     */
    public function test_recibos_internos_no_aparecen_en_el_libro(): void
    {
        // 1 factura normal
        $this->makePurchase(['taxable_total' => 1000]);

        // 1 RI en el MISMO período — debe ser excluido.
        // Usamos el proveedor genérico porque así se crean los RI en producción.
        $generico = Supplier::forInternalReceipts();
        Purchase::factory()
            ->fromSupplier($generico)
            ->withTotals(500.00)
            ->confirmada()
            ->create([
                'date'                    => '2026-04-18',
                'document_type'           => SupplierDocumentType::ReciboInterno,
                'supplier_cai'            => null,
                'supplier_invoice_number' => 'RI-20260418-0001',
                'establishment_id'        => $this->matriz->id,
            ]);

        $book = $this->service->build(2026, 4);

        // Entries: solo la factura, NO el RI
        $this->assertCount(1, $book->entries);
        $this->assertSame(
            SupplierDocumentType::Factura->value,
            $book->entries->first()->tipoDocumento,
            'El Libro de Compras SAR no debe contener Recibos Internos.',
        );

        // Summary: contadores y totales reflejan solo la factura
        $this->assertSame(1, $book->summary->facturasEmitidasCount);
        $this->assertSame(1000.00, $book->summary->facturasGravado);
        $this->assertSame(150.00, $book->summary->facturasIsv);
    }

    // ═══════════════════════════════════════════════════════════════
    // Segregación por tipo de documento (01/03/04)
    // ═══════════════════════════════════════════════════════════════

    public function test_segrega_totales_por_tipo_de_documento(): void
    {
        // 2 facturas
        $this->makePurchase(['taxable_total' => 1000]);
        $this->makePurchase(['taxable_total' => 500]);

        // 1 nota de crédito (reduce crédito fiscal)
        $this->makePurchase([
            'document_type'  => SupplierDocumentType::NotaCredito,
            'taxable_total'  => 200,
        ]);

        // 1 nota de débito (incrementa crédito fiscal)
        $this->makePurchase([
            'document_type'  => SupplierDocumentType::NotaDebito,
            'taxable_total'  => 300,
        ]);

        $book = $this->service->build(2026, 4);

        $this->assertSame(2, $book->summary->facturasEmitidasCount);
        $this->assertSame(1500.00, $book->summary->facturasGravado);
        $this->assertSame(225.00, $book->summary->facturasIsv); // 1500 * 0.15

        $this->assertSame(1, $book->summary->notasCreditoEmitidasCount);
        $this->assertSame(200.00, $book->summary->notasCreditoGravado);
        $this->assertSame(30.00, $book->summary->notasCreditoIsv);

        $this->assertSame(1, $book->summary->notasDebitoEmitidasCount);
        $this->assertSame(300.00, $book->summary->notasDebitoGravado);
        $this->assertSame(45.00, $book->summary->notasDebitoIsv);
    }

    // ═══════════════════════════════════════════════════════════════
    // Crédito fiscal neto (fórmula ISV-353)
    // ═══════════════════════════════════════════════════════════════

    public function test_credito_fiscal_neto_suma_isv_de_facturas_y_nd_resta_nc(): void
    {
        $this->makePurchase(['taxable_total' => 1000]); // ISV 150 (suma)
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaDebito,
            'taxable_total' => 400, // ISV 60 (suma)
        ]);
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaCredito,
            'taxable_total' => 200, // ISV 30 (resta)
        ]);

        $book = $this->service->build(2026, 4);

        // 150 + 60 - 30 = 180
        $this->assertSame(180.00, $book->summary->creditoFiscalNeto());
    }

    public function test_compra_neta_aplica_ajustes_nc_y_nd(): void
    {
        $this->makePurchase(['taxable_total' => 1000]); // total 1150 (suma)
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaDebito,
            'taxable_total' => 400, // total 460 (suma)
        ]);
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaCredito,
            'taxable_total' => 200, // total 230 (resta)
        ]);

        $book = $this->service->build(2026, 4);

        // 1150 + 460 - 230 = 1380
        $this->assertSame(1380.00, $book->summary->compraNeta());
    }

    public function test_gravado_y_exento_netos_aplican_mismas_reglas(): void
    {
        $this->makePurchase(['taxable_total' => 1000, 'exempt_total' => 200]);
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaDebito,
            'taxable_total' => 400,
            'exempt_total'  => 50,
        ]);
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaCredito,
            'taxable_total' => 300,
            'exempt_total'  => 100,
        ]);

        $book = $this->service->build(2026, 4);

        // gravado: 1000 + 400 - 300 = 1100
        $this->assertSame(1100.00, $book->summary->gravadoNeto());

        // exento: 200 + 50 - 100 = 150
        $this->assertSame(150.00, $book->summary->exentoNeto());
    }

    // ═══════════════════════════════════════════════════════════════
    // Anulaciones: aparecen en detalle pero NO suman en totales
    // ═══════════════════════════════════════════════════════════════

    public function test_factura_anulada_aparece_en_detalle_pero_no_suma_en_totales(): void
    {
        $this->makePurchase();                           // vigente
        $this->makePurchase(['status' => PurchaseStatus::Anulada]); // anulada

        $book = $this->service->build(2026, 4);

        $this->assertCount(2, $book->entries); // ambas en detalle
        $this->assertSame(2, $book->summary->facturasEmitidasCount);
        $this->assertSame(1, $book->summary->facturasVigentesCount);
        $this->assertSame(1, $book->summary->facturasAnuladasCount);
        $this->assertSame(1150.00, $book->summary->facturasTotal); // solo la vigente
        $this->assertSame(150.00, $book->summary->facturasIsv);
    }

    public function test_anuladas_de_cada_tipo_se_cuentan_por_separado(): void
    {
        // Facturas: 2 vigentes + 1 anulada
        $this->makePurchase();
        $this->makePurchase();
        $this->makePurchase(['status' => PurchaseStatus::Anulada]);

        // NC: 1 anulada
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaCredito,
            'status'        => PurchaseStatus::Anulada,
        ]);

        // ND: 1 vigente + 1 anulada
        $this->makePurchase(['document_type' => SupplierDocumentType::NotaDebito]);
        $this->makePurchase([
            'document_type' => SupplierDocumentType::NotaDebito,
            'status'        => PurchaseStatus::Anulada,
        ]);

        $book = $this->service->build(2026, 4);

        $this->assertSame(2, $book->summary->facturasVigentesCount);
        $this->assertSame(1, $book->summary->facturasAnuladasCount);

        $this->assertSame(0, $book->summary->notasCreditoVigentesCount);
        $this->assertSame(1, $book->summary->notasCreditoAnuladasCount);

        $this->assertSame(1, $book->summary->notasDebitoVigentesCount);
        $this->assertSame(1, $book->summary->notasDebitoAnuladasCount);
    }

    public function test_entry_anulada_marca_su_estado(): void
    {
        $this->makePurchase(['status' => PurchaseStatus::Anulada]);

        $book = $this->service->build(2026, 4);

        /** @var PurchaseBookEntry $entry */
        $entry = $book->entries->first();
        $this->assertTrue($entry->anulada);
        $this->assertFalse($entry->cuentaEnTotales());
        $this->assertSame('Anulada', $entry->estadoLabel());
    }

    // ═══════════════════════════════════════════════════════════════
    // Orden y metadatos de entries
    // ═══════════════════════════════════════════════════════════════

    public function test_entries_se_ordenan_cronologicamente(): void
    {
        $this->makePurchase(['date' => '2026-04-20']);
        $this->makePurchase(['date' => '2026-04-05']);
        $this->makePurchase(['date' => '2026-04-15']);

        $book = $this->service->build(2026, 4);

        $fechas = $book->entries->map(fn ($e) => $e->fecha->toDateString())->all();
        $this->assertSame(['2026-04-05', '2026-04-15', '2026-04-20'], $fechas);
    }

    public function test_rtn_receptor_viene_del_company_setting(): void
    {
        $this->makePurchase();

        $book = $this->service->build(2026, 4);

        /** @var PurchaseBookEntry $entry */
        $entry = $book->entries->first();
        $this->assertSame('08011999123456', $entry->rtnReceptor);
    }

    public function test_rtn_y_nombre_del_proveedor_vienen_de_la_relacion(): void
    {
        $this->makePurchase();

        $book = $this->service->build(2026, 4);

        /** @var PurchaseBookEntry $entry */
        $entry = $book->entries->first();
        $this->assertSame('05011988654321', $entry->rtnProveedor);
        $this->assertSame('Proveedor Demo S.A.', $entry->nombreProveedor);
    }

    public function test_numero_interno_y_numero_documento_proveedor_se_exponen_por_separado(): void
    {
        $this->makePurchase([
            'supplier_invoice_number' => '001-001-01-00123456',
        ]);

        $book = $this->service->build(2026, 4);

        /** @var PurchaseBookEntry $entry */
        $entry = $book->entries->first();

        // Número interno de Diproma (autogenerado COMP-YYYY-NNNNN)
        $this->assertStringStartsWith('COMP-', $entry->numeroInterno);

        // Número del documento del proveedor (lo que declara al SAR)
        $this->assertSame('001-001-01-00123456', $entry->numeroDocumentoProveedor);
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
    // Filtro por sucursal (F6a.6)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Cuando el contador descarga el libro company-wide (sin filtro) debe ver
     * todas las compras del período. Cuando filtra por sucursal específica,
     * debe ver solo las de esa sucursal — el aislamiento es a nivel SQL,
     * no de filtrado en memoria.
     */
    public function test_filtro_por_establishment_restringe_resultados(): void
    {
        $sucursal = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create();

        // 1 compra en matriz, 1 en sucursal
        $this->makePurchase(); // matriz (default)
        $this->makePurchase(['establishment_id' => $sucursal->id]);

        $libroComp     = $this->service->build(2026, 4);
        $libroMatriz   = $this->service->build(2026, 4, $this->matriz->id);
        $libroSucursal = $this->service->build(2026, 4, $sucursal->id);

        // Sin filtro: ambas
        $this->assertSame(2, $libroComp->summary->facturasEmitidasCount);

        // Filtrado por matriz: solo la de matriz
        $this->assertSame(1, $libroMatriz->summary->facturasEmitidasCount);

        // Filtrado por sucursal: solo la de sucursal
        $this->assertSame(1, $libroSucursal->summary->facturasEmitidasCount);
    }

    /**
     * Aislamiento de CONTENIDO — el count correcto no es suficiente: si el
     * filtro se aplicara invertido (intercambiando IDs), el count podría
     * coincidir pero los documentos serían los de la otra sucursal. Este
     * test valida que los números retornados son EXACTAMENTE los de la
     * sucursal solicitada.
     *
     * Cubre además cross-document-type leak: aunque el Libro de Compras lee
     * un solo modelo (Purchase con document_type discriminante), el filtro
     * debe respetarse para los tres tipos (Factura/NC/ND) simultáneamente.
     */
    public function test_filtro_por_establishment_no_incluye_compras_de_otra_sucursal(): void
    {
        $sucursal = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create();

        // Matriz: 2 facturas + 1 NC + 1 ND
        $this->makePurchase(['supplier_invoice_number' => 'MTZ-F-001']);
        $this->makePurchase(['supplier_invoice_number' => 'MTZ-F-002']);
        $this->makePurchase([
            'document_type'           => SupplierDocumentType::NotaCredito,
            'supplier_invoice_number' => 'MTZ-NC-001',
        ]);
        $this->makePurchase([
            'document_type'           => SupplierDocumentType::NotaDebito,
            'supplier_invoice_number' => 'MTZ-ND-001',
        ]);

        // Sucursal: 2 facturas + 1 NC + 1 ND
        $this->makePurchase([
            'establishment_id'        => $sucursal->id,
            'supplier_invoice_number' => 'SUC-F-001',
        ]);
        $this->makePurchase([
            'establishment_id'        => $sucursal->id,
            'supplier_invoice_number' => 'SUC-F-002',
        ]);
        $this->makePurchase([
            'establishment_id'        => $sucursal->id,
            'document_type'           => SupplierDocumentType::NotaCredito,
            'supplier_invoice_number' => 'SUC-NC-001',
        ]);
        $this->makePurchase([
            'establishment_id'        => $sucursal->id,
            'document_type'           => SupplierDocumentType::NotaDebito,
            'supplier_invoice_number' => 'SUC-ND-001',
        ]);

        // Filtro por sucursal → SOLO los 4 de sucursal
        $libroSucursal = $this->service->build(2026, 4, $sucursal->id);
        $numerosSucursal = $libroSucursal->entries
            ->pluck('numeroDocumentoProveedor')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['SUC-F-001', 'SUC-F-002', 'SUC-NC-001', 'SUC-ND-001'],
            $numerosSucursal,
            'El libro de Sucursal incluye compras que no le pertenecen (cross-leak).',
        );

        // Filtro por matriz → SOLO los 4 de matriz
        $libroMatriz = $this->service->build(2026, 4, $this->matriz->id);
        $numerosMatriz = $libroMatriz->entries
            ->pluck('numeroDocumentoProveedor')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['MTZ-F-001', 'MTZ-F-002', 'MTZ-NC-001', 'MTZ-ND-001'],
            $numerosMatriz,
            'El libro de Matriz incluye compras que no le pertenecen (cross-leak).',
        );

        // Sin filtro → los 8 juntos
        $libroComp = $this->service->build(2026, 4);
        $this->assertSame(8, $libroComp->entries->count());
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
