<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use App\Filament\Widgets\CaiStatusWidget;
use App\Filament\Widgets\FinancialStatsOverview;
use App\Filament\Widgets\FiscalPeriodsPendingWidget;
use App\Filament\Widgets\LatestSales;
use App\Filament\Widgets\LowStockAlert;
use App\Filament\Widgets\SalesByCategoryChart;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TopProductsChart;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use TomatoPHP\FilamentSettingsHub\FilamentSettingsHubPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->brandName(config('app.brand_name', 'Sistema Diproma'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            // ─── Orden y estado inicial de los grupos del sidebar ─────────
            //
            // Reorganización 2026-05-02: el sidebar tenía 8 grupos con ~20
            // items todos expandidos siempre. Ahora 6 grupos con jerarquía
            // por tipo de uso:
            //
            //   1-2. Operación + Catálogo expandidos por default — son los
            //        items que el cajero/admin tocan a diario.
            //   3-4. Documentos + Inventario expandidos — uso frecuente pero
            //        no continuo.
            //   5-6. Fiscal + Sistema colapsados por default — el contador
            //        los abre cuando declara, el admin cuando configura. El
            //        resto del tiempo no estorban.
            //
            // El acceso a cada item sigue rigiéndose por permisos Shield —
            // un técnico igual NO ve "Documentos" aunque el grupo esté en
            // la lista, porque no tiene ViewAny:Sale/Invoice/Purchase/Expense.
            // Filament oculta automáticamente los grupos sin items visibles.
            ->navigationGroups([
                NavigationGroup::make('Operación')
                    ->icon('heroicon-o-bolt')
                    ->collapsed(false),
                NavigationGroup::make('Catálogo')
                    ->icon('heroicon-o-tag')
                    ->collapsed(false),
                NavigationGroup::make('Documentos')
                    ->icon('heroicon-o-document-text')
                    ->collapsed(false),
                NavigationGroup::make('Inventario')
                    ->icon('heroicon-o-archive-box')
                    ->collapsed(false),
                NavigationGroup::make('Fiscal')
                    ->icon('heroicon-o-banknotes')
                    ->collapsed(true),
                NavigationGroup::make('Sistema')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(true),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                FiscalPeriodsPendingWidget::class, // sort 0 — Alerta fiscal (máxima prioridad visual)
                CaiStatusWidget::class,          // sort 1 — Estado de CAIs (vencimiento + agotamiento) — solo Manage:Cai
                StatsOverview::class,            // sort 1 — KPIs operativos (ventas, stock, compras)
                FinancialStatsOverview::class,   // sort 2 — KPIs financieros (ganancia, margen, ticket)
                SalesChart::class,               // sort 3 — Tendencia de ventas con período comparativo
                TopProductsChart::class,         // sort 4 — Top 10 productos (columna izquierda en xl)
                SalesByCategoryChart::class,     // sort 5 — Ventas por categoría (columna derecha en xl)
                LatestSales::class,              // sort 6 — Últimas 10 ventas con acción ver
                LowStockAlert::class,            // sort 7 — Alertas de stock con acción crear orden
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->renderHook(
                // F6a.5 — Badge de sucursal activa en topbar.
                //
                // Se monta en GLOBAL_SEARCH_AFTER (no TOPBAR_END) porque ese
                // hook vive DENTRO del contenedor .fi-topbar-end que Filament
                // ya formatea como flex con gap. TOPBAR_END en cambio se
                // renderiza *después* del cierre de ese contenedor y deja el
                // badge pegado al borde derecho del viewport sin padding.
                //
                // Orden visual resultante: [search] [🏪 Matriz] [🔔] [Avatar]
                // — contexto entre navegación global y acciones del usuario.
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => Blade::render('@livewire(\'establishment-switcher\')'),
            )
            ->sidebarCollapsibleOnDesktop();
    }
}