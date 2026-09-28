<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\Cash\Pages\ListCashSessions;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Fase 1b desde la UI: el modal "Registrar gasto" de caja y la edición del
 * gasto crean/actualizan la copia en Compras; Compras no deja anularla.
 */
class ExpenseFiscalDocumentFormTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass de Gate igual que CashSessionResourceTest: el foco es el flujo.
        Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Gate::before(fn ($user) => $user instanceof User && $user->hasRole(Utils::getSuperAdminName()) ? true : null);

        $this->cajero = User::factory()->create([
            'is_active' => true,
            'default_establishment_id' => $this->matriz->id,
        ]);
        $this->cajero->assignRole(Utils::getSuperAdminName());
        $this->actingAs($this->cajero);
    }

    /**
     * @return array<string, mixed>
     */
    private function facturaData(array $overrides = []): array
    {
        return [
            'is_isv_deductible' => true,
            'provider_name' => 'Librería Universal',
            'provider_rtn' => '08019970000001',
            'provider_invoice_number' => '001-001-01-00012345',
            'provider_invoice_cai' => 'ABCDEF-123456-789ABC-DEF012-345678-AB',
            'provider_invoice_date' => now()->toDateString(),
            'taxable_amount' => 100.00,
            'isv_amount' => 15.00,
            ...$overrides,
        ];
    }

    public function test_registrar_gasto_con_factura_desde_caja_crea_la_compra(): void
    {
        CashSession::factory()->forEstablishment($this->matriz)->openedBy($this->cajero)->openingAmount(1000)->create();

        Livewire::test(ListCashSessions::class)
            ->callAction('recordExpense', data: [
                'amount_total' => 115.00,
                'payment_method' => PaymentMethod::Efectivo->value,
                'category' => ExpenseCategory::Papeleria->value,
                'description' => 'Resma papel bond',
                'expense_date' => now()->format('Y-m-d'),
                ...$this->facturaData(),
            ])
            ->assertHasNoActionErrors();

        $expense = Expense::query()->sole();
        $this->assertEquals(100.00, (float) $expense->taxable_amount);
        $this->assertNotNull($expense->cashMovement, 'Sigue descontando del cajón.');
        $this->assertNotNull($expense->fiscalDocument, 'Y además pasa al Libro de Compras.');
        $this->assertEquals(15.00, (float) $expense->fiscalDocument->isv);
    }

    public function test_con_factura_el_importe_gravado_es_obligatorio(): void
    {
        CashSession::factory()->forEstablishment($this->matriz)->openedBy($this->cajero)->openingAmount(1000)->create();

        Livewire::test(ListCashSessions::class)
            ->callAction('recordExpense', data: [
                'amount_total' => 115.00,
                'payment_method' => PaymentMethod::Efectivo->value,
                'category' => ExpenseCategory::Papeleria->value,
                'description' => 'Resma papel bond',
                'expense_date' => now()->format('Y-m-d'),
                ...$this->facturaData(['taxable_amount' => null]),
            ])
            ->assertHasActionErrors(['taxable_amount']);

        $this->assertSame(0, Expense::count());
    }

    public function test_marcar_la_factura_al_editar_el_gasto_crea_la_compra(): void
    {
        $expense = Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'user_id' => $this->cajero->id,
            'expense_date' => now()->toDateString(),
            'amount_total' => 115.00,
            'payment_method' => PaymentMethod::Transferencia->value,
        ]);

        Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
            ->fillForm($this->facturaData())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($expense->fresh()->fiscalDocument);
    }

    public function test_editar_un_gasto_heredado_no_exige_el_gravado_ni_lo_manda_al_libro(): void
    {
        $heredado = Expense::factory()->withProvider('08019970000001')->create([
            'establishment_id' => $this->matriz->id,
            'user_id' => $this->cajero->id,
            'expense_date' => now()->toDateString(),
            'amount_total' => 115.00,
            'taxable_amount' => null,
        ]);

        Livewire::test(EditExpense::class, ['record' => $heredado->getRouteKey()])
            ->fillForm(['description' => 'Descripción corregida'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Descripción corregida', $heredado->fresh()->description);
        $this->assertSame(0, Purchase::count());
    }

    public function test_la_compra_generada_desde_un_gasto_no_se_anula_desde_compras(): void
    {
        $expense = Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'user_id' => $this->cajero->id,
            'expense_date' => now()->toDateString(),
            'amount_total' => 115.00,
            'payment_method' => PaymentMethod::Transferencia->value,
        ]);
        Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
            ->fillForm($this->facturaData())
            ->call('save');

        $purchase = $expense->fresh()->fiscalDocument;
        $this->assertSame(PurchaseStatus::Confirmada, $purchase->status);

        Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])
            ->assertActionHidden('cancel')
            ->assertSee('Generada desde un gasto');
    }
}
