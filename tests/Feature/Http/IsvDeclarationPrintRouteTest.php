<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Ruta autenticada /declaraciones-isv/{isvMonthlyDeclaration}/imprimir
 * → IsvDeclarationPrintController → IsvDeclarationPrintService.
 *
 * Mismo patrón que InvoicePrintRouteTest / CashSessionPrintRouteTest. Cubre:
 *   1. Guest redirigido al login (middleware auth).
 *   2. User sin permiso 'View:FiscalPeriod' recibe 403 (Gate::authorize).
 *   3. User con permiso ve la hoja con:
 *      - Datos del emisor (nombre, RTN, dirección) desde CompanySetting.
 *      - Período en formato humano ("Marzo 2026").
 *      - 3 secciones del Formulario 201 con totales formateados.
 *      - Banner "DECLARACIÓN VIGENTE" en snapshots activos.
 *      - Banner "DECLARACIÓN REEMPLAZADA" en snapshots supersedidos.
 *      - Número de rectificativa según posición cronológica.
 *      - Acuse SIISAR + notas cuando están presentes.
 *      - Firmas: solo declared_by si está activa; ambas si está supersedida.
 *   4. ID inexistente → 404.
 *
 * El test ejerce el Service completo a través del controller (Feature over Unit)
 * porque el Service depende de DB (CompanySetting::current, count de
 * rectificativas), lo que vuelve un unit test puro artificialmente forzado.
 * Patrón consistente con el resto del módulo de impresión.
 */
class IsvDeclarationPrintRouteTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create([
            'legal_name' => 'Diproma S. de R.L.',
            'trade_name' => 'Diproma',
            'rtn'        => '08011999000001',
            'address'    => 'Barrio Guamilito, SPS',
            'phone'      => '2550-0000',
            'email'      => 'diproma@test.com',
        ]);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        // Permiso Spatie reutilizado de FiscalPeriodPolicy@view.
        Permission::findOrCreate('View:FiscalPeriod', 'web');

        $this->reader = User::factory()->create(['is_active' => true]);
    }

    /** Helper: snapshot vigente con totales "bonitos" para aserciones. */
    private function createActiveDeclaration(int $year = 2026, int $month = 3): IsvMonthlyDeclaration
    {
        $period = FiscalPeriod::factory()->forMonth($year, $month)->create();

        return IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create([
                'ventas_gravadas'           => 100000.00,
                'ventas_exentas'            =>  10000.00,
                'ventas_totales'            => 110000.00,
                'compras_gravadas'          =>  40000.00,
                'compras_exentas'           =>   2000.00,
                'compras_totales'           =>  42000.00,
                'isv_debito_fiscal'         =>  15000.00,
                'isv_credito_fiscal'        =>   6000.00,
                'isv_retenciones_recibidas' =>    500.00,
                'saldo_a_favor_anterior'    =>      0.00,
                'isv_a_pagar'               =>   8500.00,
                'saldo_a_favor_siguiente'   =>      0.00,
                'siisar_acuse_number'       => 'SIISAR-1234567',
                'notes'                     => 'Cuadratura confirmada con libros SAR.',
            ]);
    }

    // ═══════════════════════════════════════════════════════
    // 1. Auth / Policy
    // ═══════════════════════════════════════════════════════

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $declaration = $this->createActiveDeclaration();

        $response = $this->get(route('isv-declarations.print', $declaration));

        $response->assertRedirect();
        $response->assertStatus(302);
    }

    #[Test]
    public function user_without_permission_receives_403(): void
    {
        $declaration = $this->createActiveDeclaration();

        $this->actingAs($this->reader);

        $this->get(route('isv-declarations.print', $declaration))
            ->assertForbidden();
    }

    #[Test]
    public function route_returns_404_when_declaration_does_not_exist(): void
    {
        $this->reader->givePermissionTo('View:FiscalPeriod');
        $this->actingAs($this->reader);

        $this->get(route('isv-declarations.print', ['isvMonthlyDeclaration' => 99999]))
            ->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════
    // 2. Render de snapshot vigente
    // ═══════════════════════════════════════════════════════

    #[Test]
    public function renders_active_declaration_with_all_sections(): void
    {
        $declaration = $this->createActiveDeclaration(2026, 3);

        $this->reader->givePermissionTo('View:FiscalPeriod');
        $this->actingAs($this->reader);

        $response = $this->get(route('isv-declarations.print', $declaration));

        $response->assertOk();

        // Empresa (desde CompanySetting::current)
        // El RTN se muestra con el accessor formatted_rtn: XXXX-XXXX-XXXXXX
        // (decisión de producto: legibilidad en la hoja impresa — igual que en factura).
        $response->assertSee('Diproma');
        $response->assertSee('0801-1999-000001');

        // Encabezado del documento
        $response->assertSee('Formulario 201', escape: false);
        $response->assertSee('Marzo 2026', escape: false);

        // Banner de estado vigente, NO reemplazada
        $response->assertSee('DECLARACIÓN VIGENTE', escape: false);
        $response->assertDontSee('DECLARACIÓN REEMPLAZADA', escape: false);

        // Las 3 secciones del Formulario 201
        $response->assertSee('Sección A', escape: false);
        $response->assertSee('Sección B', escape: false);
        $response->assertSee('Sección C', escape: false);

        // Totales formateados (grupo millar con coma, 2 decimales)
        $response->assertSee('100,000.00'); // ventas gravadas
        $response->assertSee('42,000.00');  // compras totales
        $response->assertSee('8,500.00');   // ISV a pagar

        // Acuse SIISAR y notas presentes
        $response->assertSee('SIISAR-1234567');
        $response->assertSee('Cuadratura confirmada con libros SAR.');

        // Botón de impresión en pantalla
        $response->assertSee('window.print()', escape: false);
    }

    #[Test]
    public function first_declaration_of_period_is_labeled_as_original(): void
    {
        $declaration = $this->createActiveDeclaration();

        $this->reader->givePermissionTo('View:FiscalPeriod');
        $this->actingAs($this->reader);

        $response = $this->get(route('isv-declarations.print', $declaration));

        $response->assertOk();
        $response->assertSee('Declaración original', escape: false);
        $response->assertDontSee('Rectificativa #', escape: false);
    }

    #[Test]
    public function second_snapshot_of_period_is_labeled_as_rectificativa_1(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        // Snapshot original (supersedido por la rectificativa siguiente)
        $original = IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_at' => now()->subDays(5)]);

        $original->update([
            'superseded_at'         => now()->subDays(1),
            'superseded_by_user_id' => $this->reader->id,
        ]);

        // Rectificativa vigente
        $rectificativa = IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_at' => now()->subDay()]);

        $this->reader->givePermissionTo('View:FiscalPeriod');
        $this->actingAs($this->reader);

        $response = $this->get(route('isv-declarations.print', $rectificativa));

        $response->assertOk();
        $response->assertSee('DECLARACIÓN VIGENTE', escape: false);
        $response->assertSee('Rectificativa #1', escape: false);
        $response->assertDontSee('Declaración original', escape: false);
    }

    // ═══════════════════════════════════════════════════════
    // 3. Render de snapshot supersedido
    // ═══════════════════════════════════════════════════════

    #[Test]
    public function renders_superseded_declaration_with_replacement_banner(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 2)->create();

        $original = IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create(['declared_at' => now()->subDays(10)]);

        $supervisor = User::factory()->create(['name' => 'Contadora Principal']);
        $original->update([
            'superseded_at'         => now()->subDays(2),
            'superseded_by_user_id' => $supervisor->id,
        ]);

        $this->reader->givePermissionTo('View:FiscalPeriod');
        $this->actingAs($this->reader);

        $response = $this->get(route('isv-declarations.print', $original));

        $response->assertOk();

        // Banner correcto + ausencia del contrario
        $response->assertSee('DECLARACIÓN REEMPLAZADA', escape: false);
        $response->assertDontSee('DECLARACIÓN VIGENTE', escape: false);

        // El snapshot supersedido es #1 cronológicamente → "Declaración original"
        $response->assertSee('Declaración original', escape: false);

        // La firma "Reemplazada por" debe mostrar al supervisor
        $response->assertSee('Reemplazada por', escape: false);
        $response->assertSee('Contadora Principal', escape: false);
    }

    // ═══════════════════════════════════════════════════════
    // 4. Campos opcionales
    // ═══════════════════════════════════════════════════════

    #[Test]
    public function renders_declaration_without_siisar_acuse_or_notes(): void
    {
        $period = FiscalPeriod::factory()->forMonth(2026, 1)->create();

        $declaration = IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create([
                'siisar_acuse_number' => null,
                'notes'               => null,
            ]);

        $this->reader->givePermissionTo('View:FiscalPeriod');
        $this->actingAs($this->reader);

        $response = $this->get(route('isv-declarations.print', $declaration));

        $response->assertOk();
        $response->assertDontSee('Acuse SIISAR', escape: false);
        $response->assertDontSee('Notas del contador', escape: false);
    }

    // ═══════════════════════════════════════════════════════
    // 5. Firmas
    // ═══════════════════════════════════════════════════════

    #[Test]
    public function active_declaration_shows_only_declared_by_signature_slot(): void
    {
        $declaredBy = User::factory()->create(['name' => 'Juan Contador']);
        $period = FiscalPeriod::factory()->forMonth(2026, 3)->create();

        $declaration = IsvMonthlyDeclaration::factory()
            ->forFiscalPeriod($period)
            ->create([
                'declared_by_user_id' => $declaredBy->id,
            ]);

        $this->reader->givePermissionTo('View:FiscalPeriod');
        $this->actingAs($this->reader);

        $response = $this->get(route('isv-declarations.print', $declaration));

        $response->assertOk();
        $response->assertSee('Declaró', escape: false);
        $response->assertSee('Juan Contador');

        // La activa NUNCA muestra "Reemplazada por" — ese slot se reserva para
        // supersedidas. En su lugar aparece "Revisó / Aprobó" (firma manual).
        $response->assertDontSee('Reemplazada por', escape: false);
        $response->assertSee('Revisó / Aprobó', escape: false);
    }
}
