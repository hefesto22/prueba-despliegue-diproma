<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gastos contables — vista admin/contador para gestión y consulta.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * RESPONSABILIDAD
 * ─────────────────────────────────────────────────────────────────────────
 * Este Resource es la pantalla de "todos los gastos del negocio" para
 * admin/contador. Sirve para:
 *   - Auditar gastos del mes (filtros por período, categoría, método, etc.).
 *   - Corregir datos fiscales mal cargados (RTN, número de factura, CAI).
 *   - Marcar/desmarcar deducibilidad ISV antes del cierre mensual.
 *
 * NO es la entrada canónica para crear gastos. La creación nace desde:
 *   1) Caja abierta → RecordExpenseAction (cajero/admin durante el día).
 *   2) Eventualmente, registro masivo desde reporte mensual (admin/contador).
 *
 * Por eso este Resource NO expone Create page — agregar esa entrada
 * implicaría duplicar la lógica de mutación del kardex (DI con
 * ExpenseService, validación de caja abierta, etc.) en otro lugar.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * EDICIÓN: SOLO CAMPOS FISCALES Y DESCRIPTIVOS
 * ─────────────────────────────────────────────────────────────────────────
 * Los campos estructurales son inmutables post-creación:
 *   - establishment_id, user_id, expense_date, payment_method, amount_total
 *
 * Razón: cambiarlos rompe trazabilidad o requiere recalcular el kardex de
 * caja (mover entre sesiones, actualizar saldos esperados, etc.) —
 * complejidad que NO vale la pena para un caso de uso "el contador
 * corrige". Si hubo error de monto o método, la solución correcta es
 * crear un gasto de ajuste o anular el original (próximo hito).
 *
 * Editables: description, category, provider_*, isv_amount, is_isv_deductible.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SIN DELETE
 * ─────────────────────────────────────────────────────────────────────────
 * La migración deja explícito que los gastos no se eliminan: son registros
 * fiscales sujetos a auditoría SAR. La Policy retorna false para delete*
 * y la tabla no expone DeleteActions — defense in depth.
 *
 * Si el negocio en el futuro requiere "anular gasto", se agrega columna
 * `voided_at` + scope + acción dedicada — distinto a un soft delete.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    /**
     * Atributo para `recordTitle` — usado en breadcrumbs y notificaciones.
     * `description` es el campo más legible (categoría es un slug interno,
     * provider_name puede estar null).
     */
    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $modelLabel = 'Gasto';

    protected static ?string $pluralModelLabel = 'Gastos';

    /**
     * Sort 2 dentro de "Finanzas" — después de Caja (sort 1) porque
     * operacionalmente la pantalla principal del cajero es Caja, y los
     * gastos son una vista de gestión que admin/contador consulta luego.
     */
    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Documentos';
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    /**
     * Eager-load para evitar N+1 en listado e infolist:
     *   - establishment: columna "Sucursal".
     *   - user:          columna "Registrado por".
     *   - cashMovement:  badge "afectó caja sí/no" + link a sesión origen.
     *
     * No hay SoftDeletes en `expenses` (decisión consciente — ver migración),
     * por eso no se omite ningún global scope acá.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['establishment:id,name', 'user:id,name', 'cashMovement:id,expense_id,cash_session_id']);
    }

    /**
     * Búsqueda global del panel — cubre los campos por los que un admin
     * típicamente busca un gasto (descripción, proveedor, RTN, factura).
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['description', 'provider_name', 'provider_rtn', 'provider_invoice_number'];
    }

    /**
     * Páginas registradas — explícitamente SIN Create.
     *
     * Ver PHPDoc de la clase para justificación. Si querés agregar Create
     * en el futuro, también hay que registrar la Page en este array y crear
     * la clase `Pages\CreateExpense.php` correspondiente.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'view'  => ViewExpense::route('/{record}'),
            'edit'  => EditExpense::route('/{record}/edit'),
        ];
    }
}
