<?php

declare(strict_types=1);

namespace App\Services\FiscalPeriods;

use App\Models\CompanySetting;
use App\Models\IsvMonthlyDeclaration;
use Illuminate\Support\Str;

/**
 * Prepara los datos de un snapshot `IsvMonthlyDeclaration` para la hoja de
 * trabajo imprimible (Formulario 201 SAR).
 *
 * Mismo patrón que InvoicePrintService / CashSessionPrintService (SRP): orquesta
 * carga eager + formato + navegación al CompanySetting vigente para que la Blade
 * `resources/views/isv-declarations/print.blade.php` sea puramente declarativa.
 *
 * NO calcula totales: los toma tal cual del snapshot (cuadratura garantizada por
 * `IsvMonthlyDeclarationService` al crear).
 * NO persiste nada: solo lee.
 * NO renderiza HTML: eso lo hace la Blade.
 *
 * POR QUÉ LA EMPRESA SE LEE DE `CompanySetting::current()` Y NO DE UN SNAPSHOT
 * ──────────────────────────────────────────────────────────────────────────
 * El modelo `IsvMonthlyDeclaration` guarda la cuadratura fiscal exacta (números
 * presentados al SAR) pero NO un snapshot de RTN / nombre / dirección de la
 * empresa. Para la hoja de trabajo eso es aceptable: en Honduras el RTN no
 * cambia en la vida de una empresa y los datos del emisor son informativos
 * (el documento oficial es el acuse SIISAR del portal). Si esto cambiara
 * —p.ej. cambio de razón social y necesidad de reimprimir hojas históricas—
 * habría que extender el snapshot con columnas `company_*`; no se hace hoy
 * (YAGNI) pero queda documentado para cuando aparezca el requerimiento.
 */
class IsvDeclarationPrintService
{
    /**
     * Retorna el payload completo para la vista de impresión de una
     * declaración ISV mensual.
     *
     * La vista espera recibir este array via compact() o ['data' => ...].
     * Todos los montos vienen pre-formateados para evitar lógica de
     * presentación en la Blade (Law of Demeter aplicado).
     *
     * @return array<string, mixed>
     */
    public function buildPrintPayload(IsvMonthlyDeclaration $declaration): array
    {
        // Carga eager de relaciones que la vista consume. loadMissing es
        // idempotente: si ya están cargadas no vuelve a querear.
        $declaration->loadMissing([
            'fiscalPeriod',
            'declaredByUser:id,name',
            'supersededByUser:id,name',
        ]);

        return [
            'declaration'  => $declaration,
            'period'       => $this->buildPeriodBlock($declaration),
            'company'      => $this->buildCompanyBlock(CompanySetting::current()),
            'status'       => $this->buildStatusBlock($declaration),
            'sections'     => $this->buildSectionsBlock($declaration),
            'siisarAcuse'  => $declaration->siisar_acuse_number,
            'notes'        => $declaration->notes,
            'signatures'   => $this->buildSignaturesBlock($declaration),
            'meta'         => $this->buildMetaBlock(),
        ];
    }

    // ─── Bloques de contenido ────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildPeriodBlock(IsvMonthlyDeclaration $declaration): array
    {
        $period = $declaration->fiscalPeriod;

        // period_label viene en minúscula desde translatedFormat('F Y') en
        // locale 'es' ("marzo 2026"). En documento fiscal formal se capitaliza
        // la primera letra del mes ("Marzo 2026"). Se hace aquí y no en el
        // modelo para no alterar el comportamiento usado por otros módulos.
        return [
            'year'  => $period->period_year,
            'month' => $period->period_month,
            'label' => Str::ucfirst($period->period_label),
        ];
    }

    /**
     * Datos del emisor tomados del CompanySetting vigente. Si no hay (entorno
     * de tests sin seed), cae a placeholders visibles pero no rompe el render.
     *
     * @return array<string, string>
     */
    private function buildCompanyBlock(?CompanySetting $company): array
    {
        if ($company === null) {
            return [
                'name'    => 'Empresa',
                'rtn'     => '',
                'address' => '',
                'phone'   => '',
                'email'   => '',
            ];
        }

        return [
            'name'    => (string) ($company->trade_name ?: $company->legal_name),
            'rtn'     => (string) ($company->formatted_rtn ?? $company->rtn),
            'address' => (string) ($company->full_address ?? $company->address),
            'phone'   => (string) $company->phone,
            'email'   => (string) $company->email,
        ];
    }

    /**
     * Estado visible del snapshot en la hoja impresa:
     *   - active (is_superseded=false) → "DECLARACIÓN VIGENTE"
     *   - superseded (is_superseded=true) → "DECLARACIÓN REEMPLAZADA"
     *
     * El número de rectificativa se calcula contando cuántos snapshots del
     * mismo período se presentaron ANTES o EN la fecha del snapshot actual
     * (orden cronológico por `declared_at`). El primero es #1 (original), el
     * segundo es #2 (primera rectificativa), y así sucesivamente. Este número
     * es el que exige el SAR en el encabezado del Formulario 201.
     *
     * @return array<string, mixed>
     */
    private function buildStatusBlock(IsvMonthlyDeclaration $declaration): array
    {
        $rectificativaNumber = IsvMonthlyDeclaration::query()
            ->where('fiscal_period_id', $declaration->fiscal_period_id)
            ->where('declared_at', '<=', $declaration->declared_at)
            ->count();

        $isSuperseded = $declaration->isSuperseded();

        return [
            'is_active'             => ! $isSuperseded,
            'is_superseded'         => $isSuperseded,
            'label'                 => $isSuperseded
                ? 'DECLARACIÓN REEMPLAZADA'
                : 'DECLARACIÓN VIGENTE',
            'rectificativa_number'  => $rectificativaNumber,
            'is_original'           => $rectificativaNumber === 1,
        ];
    }

    /**
     * Las 3 secciones del Formulario 201 SAR, todas con montos formateados.
     *
     * @return array<string, array<string, string>>
     */
    private function buildSectionsBlock(IsvMonthlyDeclaration $declaration): array
    {
        return [
            'ventas' => [
                'gravadas' => $this->formatMoney((float) $declaration->ventas_gravadas),
                'exentas'  => $this->formatMoney((float) $declaration->ventas_exentas),
                'totales'  => $this->formatMoney((float) $declaration->ventas_totales),
            ],
            'compras' => [
                'gravadas' => $this->formatMoney((float) $declaration->compras_gravadas),
                'exentas'  => $this->formatMoney((float) $declaration->compras_exentas),
                'totales'  => $this->formatMoney((float) $declaration->compras_totales),
            ],
            'isv' => [
                'debito_fiscal'           => $this->formatMoney((float) $declaration->isv_debito_fiscal),
                'credito_fiscal'          => $this->formatMoney((float) $declaration->isv_credito_fiscal),
                'retenciones_recibidas'   => $this->formatMoney((float) $declaration->isv_retenciones_recibidas),
                'saldo_a_favor_anterior'  => $this->formatMoney((float) $declaration->saldo_a_favor_anterior),
                'isv_a_pagar'             => $this->formatMoney((float) $declaration->isv_a_pagar),
                'saldo_a_favor_siguiente' => $this->formatMoney((float) $declaration->saldo_a_favor_siguiente),
            ],
        ];
    }

    /**
     * Datos para los dos espacios de firma:
     *   - declared_by: siempre presente (quien firmó la presentación).
     *   - superseded_by: solo si el snapshot fue reemplazado por una
     *     rectificativa posterior (quien firmó la rectificativa).
     *
     * @return array<string, mixed>
     */
    private function buildSignaturesBlock(IsvMonthlyDeclaration $declaration): array
    {
        return [
            'declared_by' => [
                'name' => $declaration->declaredByUser?->name ?? '—',
                'at'   => $declaration->declared_at?->format('d/m/Y H:i'),
            ],
            'superseded_by' => $declaration->isSuperseded()
                ? [
                    'name' => $declaration->supersededByUser?->name ?? '—',
                    'at'   => $declaration->superseded_at?->format('d/m/Y H:i'),
                ]
                : null,
        ];
    }

    /**
     * Metadatos de la impresión (no fiscales — no requieren snapshot).
     *
     * @return array<string, string>
     */
    private function buildMetaBlock(): array
    {
        return [
            'printed_at' => now()->format('d/m/Y H:i'),
            'printed_by' => auth()->user()?->name ?? '—',
        ];
    }

    // ─── Helpers de formato ──────────────────────────────────

    /**
     * Formato de moneda: "1,234.56" sin símbolo (L va en la vista).
     */
    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }
}
