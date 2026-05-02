<?php

namespace App\Filament\Resources\Repairs\Actions;

use App\Enums\PaymentMethod;
use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Services\Repairs\RepairDeliveryService;
use App\Services\Repairs\RepairStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

/**
 * Factory de Filament Actions para las transiciones de Reparación.
 *
 * Cada Action es contextual: solo se muestra (`->visible()`) cuando el
 * estado actual permite esa transición. La validación dura está en
 * `RepairStatusService::canTransitionTo` — el `visible()` solo evita
 * que el botón aparezca cuando claramente no aplica.
 *
 * Usado desde:
 *   - `EditRepair::getHeaderActions()` (header del editor de la reparación).
 *   - `RepairsTable::recordActions()` (acciones de fila en el listado).
 *
 * Diseño compartido para no duplicar la configuración de cada action en
 * dos lugares (DRY): un único set de definiciones, dos consumidores.
 */
class RepairTransitionActions
{
    /**
     * Acciones primarias del flujo principal (sin Anular).
     *
     * Se exponen como botones directos en la UI, no agrupadas en dropdown.
     * Cada Action tiene su propio `visible()` por estado, así que solo
     * aparece la(s) que corresponde(n) al estado actual de la reparación:
     *
     *   - Recibido      → Cotizar
     *   - Cotizado      → Aprobar + Rechazar (dos opciones simétricas)
     *   - Aprobado      → Iniciar reparación
     *   - EnReparación  → Marcar completada
     *
     * El cajero/técnico ve la próxima acción esperada como un botón directo,
     * sin tener que abrir un menú — UX más rápida y autoexplicativa.
     *
     * @return array<int, Action>
     */
    public static function primary(): array
    {
        return [
            self::cotizar(),
            self::aprobar(),
            self::rechazar(),
            self::iniciarReparacion(),
            self::marcarCompletada(),
            self::entregar(),
        ];
    }

    /**
     * Acciones secundarias (admin / excepcionales).
     *
     * - Anular: cuando la reparación está activa.
     * - Devolver anticipo: cuando hay anticipo pendiente tras estado terminal.
     * - Convertir anticipo en crédito: idem, alternativa a devolución.
     *
     * Cada Action tiene su propio `visible()`, así que el dropdown solo
     * muestra las que aplican al estado + condiciones del repair.
     *
     * @return array<int, Action>
     */
    public static function secondary(): array
    {
        return [
            self::anular(),
            self::devolverAnticipo(),
            self::convertirAnticipoEnCredito(),
        ];
    }

    /**
     * @deprecated Mantenido por compatibilidad — usar primary() + secondary().
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [...self::primary(), ...self::secondary()];
    }

    public static function cotizar(): Action
    {
        return Action::make('cotizar')
            ->label('Marcar como Cotizado')
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->visible(fn (Repair $record) => $record->status === RepairStatus::Recibido)
            ->requiresConfirmation()
            ->modalHeading('Marcar como Cotizado')
            ->modalDescription('La reparación pasa a estado Cotizado. El cliente podrá decidir si aprueba o rechaza.')
            ->modalSubmitActionLabel('Cotizar')
            ->schema([
                Textarea::make('note')
                    ->label('Nota (opcional)')
                    ->rows(2)
                    ->placeholder('Ej: Cotización válida por 7 días.'),
            ])
            ->action(function (Repair $record, array $data, RepairStatusService $service) {
                try {
                    $service->cotizar($record, $data['note'] ?? null);
                    Notification::make()
                        ->success()
                        ->title('Cotización lista')
                        ->body("Reparación {$record->repair_number} marcada como Cotizada.")
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });
    }

    public static function aprobar(): Action
    {
        return Action::make('aprobar')
            ->label('Aprobar cotización')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Repair $record) => $record->status === RepairStatus::Cotizado)
            ->modalHeading('Aprobar cotización')
            ->modalDescription('El cliente aprueba la cotización. Si deja anticipo, debe registrarse en caja.')
            ->modalSubmitActionLabel('Aprobar')
            ->schema([
                TextInput::make('advance_payment')
                    ->label('Anticipo cobrado (HNL)')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->step('0.01')
                    ->helperText('Si > 0 requiere caja abierta. Se registra como ingreso de anticipo.'),
                Textarea::make('note')
                    ->label('Nota (opcional)')
                    ->rows(2),
            ])
            ->action(function (Repair $record, array $data, RepairStatusService $service) {
                try {
                    $service->aprobar(
                        $record,
                        (float) ($data['advance_payment'] ?? 0),
                        $data['note'] ?? null,
                    );
                    Notification::make()
                        ->success()
                        ->title('Cotización aprobada')
                        ->body((float) ($data['advance_payment'] ?? 0) > 0
                            ? "Anticipo de L. " . number_format((float) $data['advance_payment'], 2) . " registrado en caja."
                            : "Reparación {$record->repair_number} lista para iniciar.")
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error al aprobar')->body($e->getMessage())->send();
                }
            });
    }

    public static function rechazar(): Action
    {
        return Action::make('rechazar')
            ->label('Rechazar cotización')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Repair $record) => $record->status === RepairStatus::Cotizado)
            ->requiresConfirmation()
            ->modalHeading('Rechazar cotización')
            ->modalDescription(fn (Repair $record) => $record->hasAdvancePayment()
                ? "⚠️ Esta reparación tiene un anticipo cobrado de L. " . number_format((float) $record->advance_payment, 2) . ". Después de rechazar, gestiona la devolución o conversión a crédito desde la reparación."
                : 'El cliente no aprueba la cotización. La reparación queda en estado terminal Rechazada.')
            ->modalSubmitActionLabel('Rechazar')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo del rechazo (opcional)')
                    ->rows(2)
                    ->placeholder('Ej: Cliente considera el precio muy alto.'),
            ])
            ->action(function (Repair $record, array $data, RepairStatusService $service) {
                try {
                    $service->rechazar($record, $data['reason'] ?? null);
                    Notification::make()
                        ->warning()
                        ->title('Cotización rechazada')
                        ->body("Reparación {$record->repair_number} marcada como Rechazada.")
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });
    }

    public static function iniciarReparacion(): Action
    {
        return Action::make('iniciar_reparacion')
            ->label('Iniciar reparación')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('primary')
            ->visible(fn (Repair $record) => $record->status === RepairStatus::Aprobado)
            ->requiresConfirmation()
            ->modalHeading('Iniciar reparación')
            ->modalDescription('El técnico comienza el trabajo. Si no había técnico asignado, se asigna el usuario actual.')
            ->modalSubmitActionLabel('Iniciar')
            ->schema([
                Textarea::make('note')
                    ->label('Nota (opcional)')
                    ->rows(2),
            ])
            ->action(function (Repair $record, array $data, RepairStatusService $service) {
                try {
                    $service->iniciarReparacion($record, null, $data['note'] ?? null);
                    Notification::make()
                        ->success()
                        ->title('Reparación iniciada')
                        ->body("Reparación {$record->repair_number} en proceso.")
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });
    }

    public static function marcarCompletada(): Action
    {
        return Action::make('marcar_completada')
            ->label('Marcar como completada')
            ->icon('heroicon-o-bell-alert')
            ->color('success')
            ->visible(fn (Repair $record) => $record->status === RepairStatus::EnReparacion)
            ->requiresConfirmation()
            ->modalHeading('Marcar como completada')
            ->modalDescription('La reparación pasa a estado "Listo para entrega". Se notificará al admin y cajero para que contacten al cliente.')
            ->modalSubmitActionLabel('Completar')
            ->schema([
                Textarea::make('note')
                    ->label('Nota para el cliente (opcional)')
                    ->rows(2)
                    ->placeholder('Ej: Reemplazada la pantalla, listo para retirar.'),
            ])
            ->action(function (Repair $record, array $data, RepairStatusService $service) {
                try {
                    $service->marcarCompletada($record, $data['note'] ?? null);
                    Notification::make()
                        ->success()
                        ->title('Listo para entrega')
                        ->body("Reparación {$record->repair_number} marcada como completada. Se notificó al admin y cajero.")
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });
    }

    /**
     * Entregar la reparación al cliente — emite Factura CAI + cobra saldo en caja.
     *
     * Solo visible cuando estado = ListoEntrega. Modal pregunta:
     *   - Método de pago (efectivo, tarjeta, transferencia).
     *   - RTN del cliente (editable: cliente puede pedir factura con RTN al recoger).
     *   - Sin CAI (toggle, default off — solo si el SAR aún no asignó CAI vigente).
     *
     * El servicio `RepairDeliveryService` hace todo en una transacción atómica:
     * crea Sale + descuenta stock + emite Invoice CAI + ingresa caja por SALDO
     * (no por total — el anticipo ya estaba en caja desde aprobación).
     */
    public static function entregar(): Action
    {
        return Action::make('entregar')
            ->label('Entregar al cliente')
            ->icon('heroicon-o-truck')
            ->color('success')
            ->visible(fn (Repair $record) => $record->status === RepairStatus::ListoEntrega)
            ->modalHeading('Entregar reparación al cliente')
            ->modalDescription(fn (Repair $record) => sprintf(
                'Total: L. %s · Anticipo: L. %s · Saldo a cobrar: L. %s. Se emitirá factura CAI y se descontará stock de las piezas internas.',
                number_format((float) $record->total, 2),
                number_format((float) $record->advance_payment, 2),
                number_format(max(0, (float) $record->total - (float) $record->advance_payment), 2),
            ))
            ->modalSubmitActionLabel('Confirmar entrega')
            ->schema([
                Select::make('payment_method')
                    ->label('Método de pago del saldo')
                    ->required()
                    ->default(PaymentMethod::Efectivo->value)
                    ->options(collect(PaymentMethod::cases())->mapWithKeys(
                        fn (PaymentMethod $m) => [$m->value => $m->getLabel()]
                    ))
                    ->helperText('Si es efectivo, requiere caja abierta.'),
                TextInput::make('customer_rtn')
                    ->label('RTN para la factura')
                    ->placeholder('0801-1999-12345')
                    ->maxLength(20)
                    ->default(fn (Repair $record) => $record->customer_rtn)
                    ->helperText('Editable. Si el cliente quiere factura con RTN, ingresa o ajusta acá. Vacío = consumidor final.'),
                Toggle::make('without_cai')
                    ->label('Emitir sin CAI (solo si no hay CAI vigente)')
                    ->default(false)
                    ->helperText('Caso excepcional. La factura sin CAI no es válida fiscalmente.'),
                Textarea::make('note')
                    ->label('Nota (opcional)')
                    ->rows(2),
            ])
            ->action(function (Repair $record, array $data, RepairDeliveryService $delivery) {
                try {
                    $paymentMethod = PaymentMethod::from($data['payment_method']);
                    $delivered = $delivery->deliver(
                        repair: $record,
                        paymentMethod: $paymentMethod,
                        customerRtnOverride: $data['customer_rtn'] ?? null,
                        withoutCai: (bool) ($data['without_cai'] ?? false),
                        note: $data['note'] ?? null,
                    );

                    Notification::make()
                        ->success()
                        ->title('Reparación entregada')
                        ->body(sprintf(
                            'Factura %s emitida. %s',
                            $delivered->invoice?->invoice_number ?? '—',
                            (float) $delivered->advance_payment > 0
                                ? 'Saldo cobrado: L. ' . number_format(
                                    max(0, (float) $delivered->total - (float) $delivered->advance_payment),
                                    2
                                )
                                : 'Total cobrado: L. ' . number_format((float) $delivered->total, 2),
                        ))
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo entregar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function devolverAnticipo(): Action
    {
        return Action::make('devolver_anticipo')
            ->label('Devolver anticipo')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (Repair $record) => $record->hasUnresolvedAdvance())
            ->requiresConfirmation()
            ->modalHeading('Devolver anticipo al cliente')
            ->modalDescription(fn (Repair $record) => sprintf(
                'Se registrará un egreso de caja de L. %s para la devolución del anticipo de %s. Requiere caja abierta.',
                number_format((float) $record->advance_payment, 2),
                $record->customer_name,
            ))
            ->modalSubmitActionLabel('Confirmar devolución')
            ->schema([
                Textarea::make('note')
                    ->label('Nota (opcional)')
                    ->rows(2)
                    ->placeholder('Ej: Devolución entregada en efectivo. Cliente firmó recibo.'),
            ])
            ->action(function (Repair $record, array $data, \App\Services\Repairs\RepairStatusService $service) {
                try {
                    $service->devolverAnticipo($record, $data['note'] ?? null);
                    Notification::make()
                        ->success()
                        ->title('Anticipo devuelto')
                        ->body(sprintf(
                            'Se registró egreso de L. %s en caja para %s.',
                            number_format((float) $record->advance_payment, 2),
                            $record->customer_name,
                        ))
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error al devolver')->body($e->getMessage())->send();
                }
            });
    }

    public static function convertirAnticipoEnCredito(): Action
    {
        return Action::make('convertir_anticipo_credito')
            ->label('Convertir anticipo en crédito')
            ->icon('heroicon-o-credit-card')
            ->color('warning')
            ->visible(fn (Repair $record) => $record->hasUnresolvedAdvance() && $record->customer_id !== null)
            ->requiresConfirmation()
            ->modalHeading('Convertir anticipo en crédito a favor')
            ->modalDescription(fn (Repair $record) => sprintf(
                'El anticipo de L. %s queda como saldo a favor de %s. NO se mueve dinero de caja — el cliente lo usa en futuras compras o reparaciones.',
                number_format((float) $record->advance_payment, 2),
                $record->customer_name,
            ))
            ->modalSubmitActionLabel('Convertir en crédito')
            ->schema([
                Textarea::make('description')
                    ->label('Descripción del crédito (opcional)')
                    ->rows(2)
                    ->placeholder('Ej: Crédito por anticipo de reparación rechazada.'),
            ])
            ->action(function (Repair $record, array $data, \App\Services\Repairs\RepairStatusService $service) {
                try {
                    $service->convertirAnticipoEnCredito($record, $data['description'] ?? null);
                    Notification::make()
                        ->success()
                        ->title('Crédito creado')
                        ->body(sprintf(
                            'L. %s quedaron como saldo a favor de %s.',
                            number_format((float) $record->advance_payment, 2),
                            $record->customer_name,
                        ))
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });
    }

    public static function anular(): Action
    {
        return Action::make('anular')
            ->label('Anular')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Repair $record) => $record->status->isActive() && $record->status !== RepairStatus::Cotizado)
            ->requiresConfirmation()
            ->modalHeading('Anular reparación')
            ->modalDescription(fn (Repair $record) => $record->hasAdvancePayment()
                ? "⚠️ Hay un anticipo cobrado de L. " . number_format((float) $record->advance_payment, 2) . ". Después de anular debes gestionar la devolución o conversión a crédito."
                : 'La reparación queda en estado terminal Anulada. Esta acción no se revierte.')
            ->modalSubmitActionLabel('Anular')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo (obligatorio para auditoría)')
                    ->rows(2)
                    ->required(),
            ])
            ->action(function (Repair $record, array $data, RepairStatusService $service) {
                try {
                    $service->anular($record, $data['reason']);
                    Notification::make()
                        ->warning()
                        ->title('Reparación anulada')
                        ->body("Reparación {$record->repair_number} anulada.")
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Error')->body($e->getMessage())->send();
                }
            });
    }
}
