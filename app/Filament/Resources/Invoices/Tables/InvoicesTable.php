<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentType;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\Cai\CaiAvailabilityService;
use App\Services\CreditNotes\CreditNoteService;
use App\Services\CreditNotes\DTOs\EmitirNotaCreditoInput;
use App\Services\CreditNotes\DTOs\LineaAcreditarInput;
use App\Services\CreditNotes\Exceptions\CantidadYaAcreditadaException;
use App\Services\CreditNotes\Exceptions\FacturaAnuladaNoAcreditableException;
use App\Services\CreditNotes\Exceptions\FacturaWithoutCaiNoAcreditableException;
use App\Services\FiscalPeriods\Exceptions\FiscalPeriodException;
use App\Services\FiscalPeriods\FiscalPeriodService;
use App\Services\Invoicing\Exceptions\InvoicingException;
use App\Services\Sales\SaleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Configuración de la tabla de Facturas para el panel admin.
 *
 * Convenciones de UI:
 *   - Las acciones que disparan efectos transaccionales (Emitir NC, Anular)
 *     envuelven siempre al Service correspondiente — nunca llaman métodos
 *     del Model directamente.
 *   - Las excepciones tipadas del dominio se traducen a Notifications
 *     con el tono adecuado (danger persistente para casos fiscales,
 *     warning para race conditions UI).
 *
 * Escalabilidad:
 *   - visible() de "Emitir NC": chequea campos locales del registro +
 *     CaiAvailabilityService::hasActiveCaiFor('03', establishment_id).
 *     El service está registrado como SINGLETON en AppServiceProvider:
 *     la primera fila dispara UNA query a cai_ranges, las siguientes
 *     leen del memo interno. Total: 1 query por render de tabla (y por
 *     combinación {tipo, establishment} en modo por_sucursal), no 50.
 *   - visible() de "Anular": delega en FiscalPeriodService::canVoidInvoice(),
 *     que NO hace query por fila. La primera invocación carga la tabla
 *     `fiscal_periods` completa a memoria (decenas de filas como máximo,
 *     una por mes desde que arrancó la operación) y las siguientes filas
 *     consultan el map ya cargado. Total: 1 query por render de tabla,
 *     no 50. Requiere que FiscalPeriodService esté registrado como
 *     SINGLETON en AppServiceProvider para que el memo persista entre
 *     las 50 resoluciones que hace Filament del closure (DI via closure
 *     type-hint, no service locator).
 *   - El form de "Emitir NC" ejecuta UNA sola query agregada al abrir el
 *     modal (ver buildAvailableLines). Usa los índices existentes
 *     credit_note_items.sale_item_id y credit_notes.invoice_id.
 */
class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('No. Factura')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    ->color(fn (Invoice $record): string => $record->is_void ? 'danger' : 'primary'),

                TextColumn::make('invoice_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('customer_rtn')
                    ->label('RTN')
                    ->searchable()
                    ->placeholder('Consumidor Final')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->alignEnd(),

                TextColumn::make('isv')
                    ->label('ISV')
                    ->money('HNL')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Estado derivado de is_void:
                //   is_void=true  → anulada → X roja
                //   is_void=false → válida  → check verde
                // Sin getStateUsing intermedio — la doble negación previa
                // terminaba mostrando el icono invertido en la UI (válida se
                // veía como X y anulada como check). El nombre de la columna
                // ya es `is_void`, así que la semántica nativa del boolean
                // casa 1:1 con el flag del modelo: "¿está anulada? sí/no".
                IconColumn::make('is_void')
                    ->label('Estado')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->tooltip(fn (Invoice $record): string => $record->is_void ? 'Anulada' : 'Válida'),

                IconColumn::make('without_cai')
                    ->label('CAI')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-mark')
                    ->falseIcon('heroicon-o-check')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->getStateUsing(fn (Invoice $record): bool => !$record->without_cai)
                    ->tooltip(fn (Invoice $record): string => $record->without_cai ? 'Sin CAI' : 'Con CAI'),

                TextColumn::make('sale.sale_number')
                    ->label('Venta')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('creator.name')
                    ->label('Creador')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options([
                        'valid' => 'Válidas',
                        'void' => 'Anuladas',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value']) {
                            'valid' => $query->where('is_void', false),
                            'void' => $query->where('is_void', true),
                            default => $query,
                        };
                    }),

                SelectFilter::make('cai')
                    ->options([
                        'con_cai' => 'Con CAI',
                        'sin_cai' => 'Sin CAI',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value']) {
                            'con_cai' => $query->where('without_cai', false),
                            'sin_cai' => $query->where('without_cai', true),
                            default => $query,
                        };
                    }),

                Filter::make('invoice_date')
                    ->label('Fecha')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('Desde'),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('Hasta'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('invoice_date', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('invoice_date', '<=', $date));
                    }),
            ])
            ->actions([
                Action::make('imprimir')
                    ->label('Ver / Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('primary')
                    // Navega a la página interna del panel que embebe la
                    // factura en iframe (misma pestaña, sidebar/navbar
                    // visibles). Antes abría nueva pestaña con la URL
                    // standalone — Mauricio pidió mantener el chrome del
                    // panel para no perder contexto de navegación.
                    ->url(fn (Invoice $record): string => InvoiceResource::getUrl('print', ['record' => $record])),

                Action::make('url_publica')
                    ->label('Copiar link verificación')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (Invoice $record): bool => ! empty($record->integrity_hash))
                    ->action(function (Invoice $record): void {
                        // La URL se devuelve en la notificacion; el usuario la copia.
                        // No podemos escribir en el clipboard del navegador desde PHP,
                        // asi que la mostramos en una notificacion persistente con
                        // el texto seleccionable.
                        Notification::make()
                            ->title('URL de verificación pública')
                            ->body(route('invoices.verify', ['hash' => $record->integrity_hash]))
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                Action::make('emitir_nota_credito')
                    ->label('Emitir NC')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('warning')
                    ->modalHeading(fn (Invoice $record): string => "Emitir Nota de Crédito sobre {$record->invoice_number}")
                    ->modalDescription(
                        'Indicá la cantidad a acreditar por línea. La "Devolución física" '
                        . 'retornará stock al kardex; las demás razones son solo ajustes '
                        . 'fiscales. La cantidad máxima respeta NCs previas no anuladas.'
                    )
                    ->modalSubmitActionLabel('Emitir NC')
                    ->modalWidth('5xl')
                    // Visibilidad:
                    //   1. factura no anulada          (campo ya traído del SELECT)
                    //   2. factura emitida con CAI     (idem)
                    //   3. existe un CAI EMISOR ACTIVO para el tipo '03' (NC) en
                    //      el alcance del establecimiento → sin esto el botón
                    //      prometería una acción que el resolver de correlativos
                    //      rechazaría con NoHayCaiActivoException al submit.
                    //
                    // El chequeo (3) usa CaiAvailabilityService, registrado como
                    // singleton en AppServiceProvider: la PRIMERA fila pega una
                    // query a cai_ranges; las siguientes 49 leen del memo
                    // interno. Total: 1 query por render de tabla, no 50.
                    // Si la factura está 100% acreditada igual se muestra el
                    // botón — el Repeater renderizará vacío y el submit se
                    // bloqueará. Trade-off deliberado: costo mínimo de UX vs.
                    // query extra por fila del listado.
                    ->visible(fn (Invoice $record, CaiAvailabilityService $caiAvailability): bool =>
                        ! $record->is_void
                        && ! $record->without_cai
                        && $caiAvailability->hasActiveCaiFor(DocumentType::NotaCredito, $record->establishment_id)
                    )
                    ->form([
                        Repeater::make('lineas')
                            ->label('Líneas a acreditar')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => $state['producto'] ?? null)
                            ->schema([
                                // Campos ocultos que alimentan validaciones y DTOs.
                                Hidden::make('sale_item_id'),
                                Hidden::make('disponible'),
                                Hidden::make('vendida'),
                                Hidden::make('ya_acreditada'),

                                TextInput::make('producto')
                                    ->label('Producto')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpan(3),

                                TextInput::make('quantity')
                                    ->label('A acreditar')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(fn ($get) => (int) $get('disponible'))
                                    ->required()
                                    ->helperText(fn ($get): string =>
                                        'Vendida: ' . (int) $get('vendida')
                                        . ' · Ya NC: ' . (int) $get('ya_acreditada')
                                        . ' · Disponible: ' . (int) $get('disponible')
                                    )
                                    ->columnSpan(2),
                            ])
                            ->columns(5),

                        Select::make('reason')
                            ->label('Razón')
                            ->options(collect(CreditNoteReason::cases())
                                ->mapWithKeys(fn (CreditNoteReason $r) => [$r->value => $r->getLabel()])
                                ->all())
                            ->required()
                            ->native(false)
                            ->live()
                            ->helperText(fn ($state): ?string => match ($state) {
                                CreditNoteReason::DevolucionFisica->value =>
                                    '⚠️ Los productos volverán al inventario (kardex: EntradaNotaCredito).',
                                null, '' => null,
                                default => 'Solo ajuste fiscal. No toca inventario. Requiere notas explicativas.',
                            }),

                        Textarea::make('reason_notes')
                            ->label('Notas explicativas')
                            ->rows(3)
                            ->maxLength(500)
                            // Obligatorio cuando razón != DevolucionFisica (regla del
                            // enum::requiresNotes, que el DTO también valida).
                            ->required(fn ($get): bool =>
                                $get('reason') !== null
                                && $get('reason') !== ''
                                && $get('reason') !== CreditNoteReason::DevolucionFisica->value
                            )
                            ->visible(fn ($get): bool =>
                                $get('reason') !== null
                                && $get('reason') !== ''
                                && $get('reason') !== CreditNoteReason::DevolucionFisica->value
                            ),
                    ])
                    // Pre-población del Repeater con UNA query agregada por invoice
                    // (ver buildAvailableLines). Se ejecuta al abrir el modal, no
                    // al renderizar la tabla.
                    ->fillForm(fn (Invoice $record): array => [
                        'lineas' => self::buildAvailableLines($record),
                    ])
                    ->action(function (Invoice $record, array $data, CreditNoteService $creditNotes): void {
                        try {
                            // Filtrar líneas con quantity=0 (el operador las dejó vacías
                            // porque no quería acreditarlas). El DTO rechaza array vacío.
                            $lineas = collect($data['lineas'] ?? [])
                                ->filter(fn (array $l): bool => (int) ($l['quantity'] ?? 0) > 0)
                                ->map(fn (array $l): LineaAcreditarInput => new LineaAcreditarInput(
                                    saleItemId: (int) $l['sale_item_id'],
                                    quantity:   (int) $l['quantity'],
                                ))
                                ->values()
                                ->all();

                            if ($lineas === []) {
                                Notification::make()
                                    ->title('Debe acreditar al menos una línea')
                                    ->body('Indicá cantidad > 0 en al menos un ítem antes de emitir.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $input = new EmitirNotaCreditoInput(
                                invoice:     $record,
                                reason:      CreditNoteReason::from($data['reason']),
                                lineas:      $lineas,
                                reasonNotes: $data['reason_notes'] ?? null,
                            );

                            $creditNote = $creditNotes->generateFromInvoice($input);

                            Notification::make()
                                ->title('Nota de Crédito emitida')
                                ->body("NC {$creditNote->credit_note_number} creada sobre {$record->invoice_number}.")
                                ->success()
                                ->actions([
                                    NotificationAction::make('imprimir')
                                        ->label('Imprimir NC')
                                        // URL interna del panel (PrintCreditNote) — el
                                        // recibo se embebe en iframe dentro de Filament
                                        // manteniendo sidebar/navbar. Simétrico a la
                                        // acción "Imprimir" de la tabla y de ViewCreditNote.
                                        ->url(CreditNoteResource::getUrl('print', ['record' => $creditNote])),
                                ])
                                ->persistent()
                                ->send();
                        } catch (FacturaAnuladaNoAcreditableException | FacturaWithoutCaiNoAcreditableException $e) {
                            // Regla fiscal infringida: factura no elegible para NC.
                            // Persistente porque el usuario debe leer el motivo exacto.
                            Notification::make()
                                ->title('No se puede emitir NC sobre esta factura')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (CantidadYaAcreditadaException $e) {
                            // Race condition: otro operador emitió NC entre render y
                            // submit, reduciendo el disponible. El mensaje del dominio
                            // incluye producto, solicitada, disponible y ya acreditada.
                            Notification::make()
                                ->title('Cantidad no disponible')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (InvoicingException $e) {
                            // Cubre los 4 errores del resolver de correlativos/CAI:
                            //   - NoHayCaiActivoException: no hay CAI registrado para
                            //     tipo '03' en la empresa/establecimiento.
                            //   - CaiVencidoException: el CAI existe pero expiró.
                            //   - RangoCaiAgotadoException: se acabó el rango de
                            //     numeración disponible.
                            //   - TransaccionRequeridaException: bug de código
                            //     (el Service debería garantizarlo); si cae acá
                            //     el mensaje ayuda a diagnosticar.
                            // Todos son situaciones operativas: el mensaje del dominio
                            // ya dice qué hacer (ej. "Registre un nuevo CAI en
                            // Administración antes de continuar").
                            Notification::make()
                                ->title('Problema con el CAI fiscal')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (\InvalidArgumentException $e) {
                            // Del DTO: líneas duplicadas, razón sin notas. Un InvalidArgument
                            // aquí indica un bug de UI (los forms no deberían permitir estos
                            // casos) — mensaje claro para poder diagnosticar.
                            Notification::make()
                                ->title('Datos inválidos')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                // ─── Anular factura (F5c + F5d) ───────────────────
                // Cascada: invoice → sale → reversa de kardex → devolución de stock.
                // Gate fiscal: solo visible/ejecutable si el período está ABIERTO
                // y la factura es posterior a fiscal_period_start (no pre-tracking).
                // Si el período fue declarado al SAR, la corrección válida es NC.
                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('¿Anular esta factura?')
                    ->modalDescription(
                        'Se anulará la factura Y SU VENTA asociada: el stock de los productos '
                        . 'se devolverá al inventario con movimientos de kardex y la venta quedará '
                        . 'marcada como Anulada. Esta acción solo es válida si el período fiscal aún '
                        . 'no fue declarado al SAR.'
                    )
                    ->modalSubmitActionLabel('Sí, anular con cascada')
                    // Visibilidad: factura no anulada + período abierto + no pre-tracking.
                    // canVoidInvoice() hace los 3 checks sin lanzar excepción.
                    // DI via closure: Filament resuelve FiscalPeriodService desde el
                    // container. El singleton registrado en AppServiceProvider mantiene
                    // el memo interno entre las 50 llamadas por render de tabla.
                    ->visible(fn (Invoice $record, FiscalPeriodService $fiscal): bool =>
                        $fiscal->canVoidInvoice($record)
                    )
                    ->action(function (Invoice $record, FiscalPeriodService $fiscal, SaleService $sales): void {
                        try {
                            // Red de seguridad: el período pudo cambiar entre render y click
                            // (admin declaró el período mientras el cajero tenía abierta la tabla).
                            $fiscal->assertCanVoidInvoice($record);

                            $sale = $record->sale;

                            if ($sale === null) {
                                // Edge case: factura sin venta asociada (dato histórico
                                // inconsistente pre-módulo de cascade). Fallback: marcar
                                // la factura como void sin efectos en stock.
                                $record->void();

                                Notification::make()
                                    ->title('Factura anulada (sin cascada)')
                                    ->body("La factura {$record->invoice_number} no tiene venta asociada, se anuló solo el documento fiscal. No hubo devolución de stock. Revise en auditoría por qué no tenía venta vinculada.")
                                    ->warning()
                                    ->persistent()
                                    ->send();

                                return;
                            }

                            $sales->cancel($sale);

                            Notification::make()
                                ->title('Factura y venta anuladas')
                                ->body("Factura {$record->invoice_number} y venta {$sale->sale_number} fueron anuladas. El stock se restauró al inventario.")
                                ->success()
                                ->send();
                        } catch (FiscalPeriodException $e) {
                            Notification::make()
                                ->title('No se puede anular esta factura')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (\InvalidArgumentException $e) {
                            // Venta ya anulada (SaleService::cancel lanza InvalidArgumentException).
                            Notification::make()
                                ->title('La venta ya estaba anulada')
                                ->body($e->getMessage())
                                ->warning()
                                ->send();
                        } catch (\Throwable $e) {
                            report($e);
                            Notification::make()
                                ->title('Error al anular la factura')
                                ->body('Ocurrió un error inesperado. Se registró en logs para diagnóstico. Contacte al soporte técnico.')
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->striped()
            ->paginated([10, 25, 50]);
    }

    /**
     * Query agregada O(1) que calcula disponible-por-línea para una factura.
     *
     * Usa los índices existentes:
     *   - sale_items.sale_id (FK auto-indexada)
     *   - credit_note_items.sale_item_id (índice dedicado en migración)
     *   - credit_notes.invoice_id (índice dedicado en migración)
     *   - products.id (PK)
     *
     * LEFT JOIN con filtro `is_void = 0` en la condición del JOIN (no en WHERE)
     * para preservar sale_items sin NC previa. MAX() sobre columnas no agregadas
     * para compatibilidad con sql_mode=ONLY_FULL_GROUP_BY.
     *
     * Devuelve solo líneas con disponible > 0 — las 100% acreditadas no se
     * muestran en el Repeater. Si el resultado queda vacío, el Repeater
     * renderiza sin filas y el submit es bloqueado por la validación del
     * action closure.
     *
     * @return list<array{
     *   sale_item_id: int,
     *   producto: string,
     *   vendida: int,
     *   ya_acreditada: int,
     *   disponible: int,
     *   quantity: int,
     * }>
     */
    private static function buildAvailableLines(Invoice $invoice): array
    {
        return DB::table('sale_items')
            ->where('sale_items.sale_id', $invoice->sale_id)
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('credit_note_items', 'credit_note_items.sale_item_id', '=', 'sale_items.id')
            ->leftJoin('credit_notes', function ($join) {
                $join->on('credit_notes.id', '=', 'credit_note_items.credit_note_id')
                    ->where('credit_notes.is_void', false);
            })
            ->groupBy('sale_items.id')
            ->selectRaw('
                sale_items.id                               as sale_item_id,
                MAX(products.sku)                           as product_sku,
                MAX(products.name)                          as product_name,
                MAX(sale_items.quantity)                    as vendida,
                COALESCE(SUM(credit_note_items.quantity), 0) as ya_acreditada
            ')
            ->get()
            ->map(function ($row): array {
                $vendida     = (int) $row->vendida;
                $yaAcreditada = (int) $row->ya_acreditada;
                $disponible  = $vendida - $yaAcreditada;

                return [
                    'sale_item_id'  => (int) $row->sale_item_id,
                    'producto'      => "{$row->product_sku} — {$row->product_name}",
                    'vendida'       => $vendida,
                    'ya_acreditada' => $yaAcreditada,
                    'disponible'    => $disponible,
                    'quantity'      => 0,
                ];
            })
            ->filter(fn (array $row): bool => $row['disponible'] > 0)
            ->values()
            ->all();
    }
}
