<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cash\Schemas;

use App\Models\CashSession;
use App\Services\Cash\CashBalanceCalculator;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Infolist de una sesión de caja — construido 100% con componentes nativos
 * de Filament.
 *
 * Por qué no ViewEntry + blades custom (intento anterior): los utility classes
 * de Tailwind en blades fuera de los paths escaneados por el build de Filament
 * pueden no purgarse correctamente, y el layout de Schema (2 columnas default)
 * requería micro-tunning. Las Sections/Grid nativas heredan el design system,
 * los tokens semánticos (success/warning/danger/primary/info/gray) están
 * garantizados, y el resultado queda consistente con el resto del panel.
 *
 * Estructura (de mayor a menor jerarquía):
 *   1. Hero         → Section con aside() — icono + estado + monto destacado.
 *   2. Stats        → Grid de 3 Sections (Apertura · Movimiento · Cierre).
 *   3. Desglose     → Section con el detalle contable del cierre (si cerrada).
 *   4. Detalles     → Section colapsada con metadatos operativos.
 *
 * Schema en 1 columna para que cada bloque ocupe todo el ancho — necesario
 * porque el default de infolist es grid de 2 cols y componentes top-level
 * quedaban repartidos lado a lado.
 *
 * Performance: el CashBalanceCalculator se invoca varias veces, pero como ya
 * está fixeado para usar `$session->movements` (accessor cacheado), el primer
 * acceso lazy-loadea y los siguientes reutilizan la misma Collection.
 */
class CashSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                self::heroSection(),
                self::statsGrid(),
                self::closeBreakdownSection(),
                self::operationalDetailsSection(),
            ]);
    }

    // ─── Hero ─────────────────────────────────────────────────────
    //
    // Section con aside(): el bloque lateral izquierdo muestra icono + título
    // + subtítulo semántico; el lateral derecho muestra el monto destacado
    // con su etiqueta. Esto da el efecto "dashboard hero" sin blades custom.

    private static function heroSection(): Section
    {
        return Section::make()
            ->heading(fn (CashSession $record): string => self::heroTitle($record))
            ->description(fn (CashSession $record): string => self::heroSubtitle($record))
            ->icon(fn (CashSession $record): string => self::heroIcon($record))
            ->iconColor(fn (CashSession $record): string => self::heroColor($record))
            ->aside()
            ->schema([
                TextEntry::make('hero_amount')
                    ->hiddenLabel()
                    ->state(fn (CashSession $record): string => self::heroAmount($record))
                    ->size('2xl')
                    ->weight('bold')
                    ->color(fn (CashSession $record): string => self::heroColor($record))
                    ->helperText(fn (CashSession $record): string => self::heroAmountLabel($record)),
            ]);
    }

    // ─── Stats (3 cards en línea) ─────────────────────────────────

    private static function statsGrid(): Grid
    {
        // Responsive: apila en mobile (1 col), 3 en línea desde tablet (md+).
        // Filament expande el array a breakpoints CSS automáticamente.
        return Grid::make(['default' => 1, 'md' => 3])
            ->schema([
                self::openingCard(),
                self::movementCard(),
                self::closingCard(),
            ]);
    }

    private static function openingCard(): Section
    {
        return Section::make('Apertura')
            ->icon('heroicon-o-lock-open')
            ->iconColor('info')
            ->schema([
                TextEntry::make('opening_amount')
                    ->hiddenLabel()
                    ->money('HNL')
                    ->size('xl')
                    ->weight('bold'),

                TextEntry::make('openedBy.name')
                    ->label('Abierta por')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->placeholder('—'),

                TextEntry::make('opened_at')
                    ->label('Fecha')
                    ->icon('heroicon-o-calendar')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray'),
            ]);
    }

    private static function movementCard(): Section
    {
        return Section::make('Movimiento')
            ->icon('heroicon-o-arrows-right-left')
            ->iconColor('primary')
            ->schema([
                TextEntry::make('expected_cash')
                    ->hiddenLabel()
                    ->state(fn (CashSession $record): float => self::calculator()->expectedCash($record))
                    ->money('HNL')
                    ->size('xl')
                    ->weight('bold')
                    ->helperText('Esperado en caja'),

                TextEntry::make('inflows')
                    ->label('Ingresos')
                    ->state(fn (CashSession $record): float => self::calculator()->totalCashInflows($record))
                    ->money('HNL')
                    ->color('success')
                    ->icon('heroicon-o-arrow-trending-up'),

                TextEntry::make('outflows')
                    ->label('Egresos')
                    ->state(fn (CashSession $record): float => self::calculator()->totalCashOutflows($record))
                    ->money('HNL')
                    ->color('danger')
                    ->icon('heroicon-o-arrow-trending-down'),
            ]);
    }

    private static function closingCard(): Section
    {
        return Section::make('Cierre')
            ->icon(fn (CashSession $record): string => $record->isClosed() ? 'heroicon-o-lock-closed' : 'heroicon-o-clock')
            ->iconColor(fn (CashSession $record): string => $record->isClosed() ? 'success' : 'gray')
            ->schema([
                TextEntry::make('actual_closing_amount')
                    ->hiddenLabel()
                    ->money('HNL')
                    ->size('xl')
                    ->weight('bold')
                    ->placeholder('Pendiente')
                    ->helperText(fn (CashSession $record): string => $record->isClosed()
                        ? 'Monto contado físico'
                        : 'Sesión en operación'
                    ),

                TextEntry::make('closedBy.name')
                    ->label('Cerrada por')
                    ->icon('heroicon-o-user')
                    ->color('gray')
                    ->visible(fn (CashSession $record): bool => $record->isClosed())
                    ->placeholder('—'),

                TextEntry::make('closed_at')
                    ->label('Fecha')
                    ->icon('heroicon-o-calendar')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->visible(fn (CashSession $record): bool => $record->isClosed()),
            ]);
    }

    // ─── Desglose del cierre (solo si cerrada) ────────────────────

    private static function closeBreakdownSection(): Section
    {
        return Section::make('Desglose del cierre')
            ->icon('heroicon-o-clipboard-document-check')
            ->columns(3)
            ->visible(fn (CashSession $record): bool => $record->isClosed())
            ->schema([
                TextEntry::make('expected_closing_amount')
                    ->label('Esperado en sistema')
                    ->money('HNL')
                    ->placeholder('—'),

                TextEntry::make('actual_closing_amount')
                    ->label('Contado físico')
                    ->money('HNL')
                    ->weight('bold')
                    ->placeholder('—'),

                TextEntry::make('discrepancy')
                    ->label('Descuadre')
                    ->money('HNL')
                    ->weight('bold')
                    ->placeholder('—')
                    ->color(function ($state): string {
                        if ($state === null) {
                            return 'gray';
                        }
                        $value = (float) $state;
                        if ($value === 0.0) {
                            return 'success';
                        }

                        return $value > 0 ? 'warning' : 'danger';
                    })
                    ->helperText(function ($state): ?string {
                        if ($state === null) {
                            return null;
                        }
                        $value = (float) $state;
                        if ($value === 0.0) {
                            return 'Caja cuadrada exactamente';
                        }

                        return $value > 0 ? 'Sobra dinero' : 'Falta dinero';
                    }),

                TextEntry::make('authorizedBy.name')
                    ->label('Descuadre autorizado por')
                    ->icon('heroicon-o-shield-check')
                    ->placeholder('No requerido')
                    ->columnSpan(2),

                TextEntry::make('notes')
                    ->label('Observaciones')
                    ->placeholder('Sin observaciones')
                    ->columnSpanFull(),
            ]);
    }

    // ─── Detalles operativos (colapsable) ─────────────────────────

    private static function operationalDetailsSection(): Section
    {
        return Section::make('Detalles operativos')
            ->icon('heroicon-o-information-circle')
            ->columns(3)
            ->collapsible()
            ->collapsed()
            ->schema([
                TextEntry::make('id')
                    ->label('# Sesión')
                    ->prefix('#')
                    ->weight('bold'),

                TextEntry::make('establishment.name')
                    ->label('Sucursal')
                    ->icon('heroicon-o-building-storefront'),

                TextEntry::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (CashSession $record): string => $record->isOpen() ? 'Abierta' : 'Cerrada')
                    ->color(fn (CashSession $record): string => $record->isOpen() ? 'success' : 'gray')
                    ->icon(fn (CashSession $record): string => $record->isOpen() ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed'),

                TextEntry::make('created_at')
                    ->label('Creada en sistema')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray'),

                TextEntry::make('updated_at')
                    ->label('Última actualización')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray'),
            ]);
    }

    // ─── Builders del hero ────────────────────────────────────────
    //
    // Encapsulan la lógica de status → presentación. Mantienen el `configure()`
    // declarativo y permiten testing aislado si a futuro se agregan casos
    // (sesión pausada, sesión en auditoría, etc.).

    private static function heroTitle(CashSession $record): string
    {
        if ($record->isOpen()) {
            return 'Sesión en operación';
        }

        $discrepancy = (float) ($record->discrepancy ?? 0);

        return match (true) {
            $discrepancy === 0.0 => 'Caja cuadrada exactamente',
            $discrepancy > 0     => 'Sobra dinero en caja',
            default              => 'Faltante en caja',
        };
    }

    private static function heroSubtitle(CashSession $record): string
    {
        $sucursal = $record->establishment?->name ?? 'Sucursal desconocida';

        if ($record->isOpen()) {
            return "{$sucursal} · Abierta el " . $record->opened_at->format('d/m/Y H:i');
        }

        $closedAt = $record->closed_at?->format('d/m/Y H:i') ?? '—';

        return "{$sucursal} · Cerrada el {$closedAt}";
    }

    private static function heroIcon(CashSession $record): string
    {
        if ($record->isOpen()) {
            return 'heroicon-o-lock-open';
        }

        $discrepancy = (float) ($record->discrepancy ?? 0);

        return match (true) {
            $discrepancy === 0.0 => 'heroicon-o-check-badge',
            $discrepancy > 0     => 'heroicon-o-exclamation-triangle',
            default              => 'heroicon-o-x-circle',
        };
    }

    /**
     * Color semántico de Filament (success/warning/danger/primary/info/gray).
     * Aplicado tanto al icono del Section como al color del monto destacado,
     * para que el estado fiscal sea leíble de un vistazo.
     */
    private static function heroColor(CashSession $record): string
    {
        if ($record->isOpen()) {
            return 'primary';
        }

        $discrepancy = (float) ($record->discrepancy ?? 0);

        return match (true) {
            $discrepancy === 0.0 => 'success',
            $discrepancy > 0     => 'warning',
            default              => 'danger',
        };
    }

    private static function heroAmount(CashSession $record): string
    {
        if ($record->isOpen()) {
            return self::formatMoney(self::calculator()->expectedCash($record));
        }

        $discrepancy = (float) ($record->discrepancy ?? 0);

        return self::formatMoney(abs($discrepancy));
    }

    private static function heroAmountLabel(CashSession $record): string
    {
        if ($record->isOpen()) {
            return 'esperado en caja al cierre';
        }

        $discrepancy = (float) ($record->discrepancy ?? 0);

        return match (true) {
            $discrepancy === 0.0 => 'de descuadre — cuadre exacto',
            $discrepancy > 0     => 'de sobrante',
            default              => 'de faltante',
        };
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private static function calculator(): CashBalanceCalculator
    {
        return app(CashBalanceCalculator::class);
    }

    private static function formatMoney(float $amount): string
    {
        return 'L ' . number_format($amount, 2, '.', ',');
    }
}
