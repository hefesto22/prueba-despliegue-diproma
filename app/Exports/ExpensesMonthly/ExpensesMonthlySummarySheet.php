<?php

declare(strict_types=1);

namespace App\Exports\ExpensesMonthly;

use App\Services\Expenses\ExpensesMonthlyReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Hoja "Resumen" del Reporte Mensual de Gastos.
 *
 * KPIs y desgloses que el contador necesita ver al abrir el archivo:
 *   - Totales del período (count + monto)
 *   - Deducibles de ISV (`creditoFiscalDeducible` que va al Formulario 201)
 *   - Alerta de deducibles incompletos (filas que SAR podría rechazar)
 *   - Impacto en caja (afecta vs no afecta saldo físico)
 *   - Desglose por categoría / método de pago / sucursal
 *
 * Layout declarativo idéntico al patrón de `PurchaseBookSummarySheet`:
 *   - Cada fila se registra con su tipo (title, section_header, key_value,
 *     money, highlight, alert, spacer) y eso decide su número de fila Y su
 *     estilo en `registerEvents`.
 *   - Agregar / reordenar filas no requiere tocar `registerEvents` — los
 *     estilos siguen al layout.
 *
 * Color semantics:
 *   - Verde (highlight) → crédito fiscal deducible (positivo, es lo que el
 *     contador toma para el F201). Mismo verde que el PurchaseBook usa para
 *     "crédito fiscal neto" → coherencia visual entre reportes fiscales.
 *   - Naranja (alert)   → deducibles incompletos (riesgo). Solo aparece si
 *     hay alertas reales — se omite cuando count = 0 para no introducir
 *     ruido visual cuando todo está limpio.
 */
class ExpensesMonthlySummarySheet implements FromCollection, WithTitle, WithEvents, WithColumnWidths, ShouldAutoSize
{
    // Tipos de fila — gobiernan rendering Y estilo.
    private const ROW_TITLE          = 'title';          // título principal, fondo oscuro, merge
    private const ROW_SECTION_HEADER = 'section_header'; // encabezado de sección, fondo medio, merge
    private const ROW_KEY_VALUE      = 'key_value';      // concepto | valor (entero/string)
    private const ROW_MONEY          = 'money';          // concepto | valor monetario (#,##0.00)
    private const ROW_HIGHLIGHT      = 'highlight';      // fila destacada (crédito fiscal deducible)
    private const ROW_ALERT          = 'alert';          // fila de alerta (deducibles incompletos)
    private const ROW_SPACER         = 'spacer';         // fila vacía

    /**
     * Layout declarativo. Única fuente de verdad para rendering + estilos.
     *
     * @var list<array{type: string, label: string, value: int|float|string}>
     */
    private array $layout = [];

    public function __construct(
        private readonly ExpensesMonthlyReport $report,
    ) {
        $this->buildLayout();
    }

    private function buildLayout(): void
    {
        $s = $this->report->summary;

        $this->layout = [
            ['type' => self::ROW_TITLE,     'label' => 'REPORTE MENSUAL DE GASTOS', 'value' => ''],
            ['type' => self::ROW_KEY_VALUE, 'label' => 'Período',                   'value' => $s->periodLabel()],
            ['type' => self::ROW_SPACER,    'label' => '',                          'value' => ''],

            // ── Totales globales del período ─────────────────
            ['type' => self::ROW_SECTION_HEADER, 'label' => 'TOTALES DEL PERÍODO', 'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Cantidad de gastos',  'value' => $s->gastosCount],
            ['type' => self::ROW_MONEY,          'label' => 'Monto total',         'value' => $s->gastosTotal],
            ['type' => self::ROW_SPACER,         'label' => '',                    'value' => ''],

            // ── Deducibles de ISV (lo que importa para el F201) ──
            ['type' => self::ROW_SECTION_HEADER, 'label' => 'DEDUCIBLES DE ISV (Formulario 201)', 'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Cantidad deducibles',          'value' => $s->deduciblesCount],
            ['type' => self::ROW_MONEY,          'label' => 'Total gastos deducibles',      'value' => $s->deduciblesTotal],
            ['type' => self::ROW_HIGHLIGHT,      'label' => 'Crédito fiscal deducible',     'value' => $s->creditoFiscalDeducible],
        ];

        // Alerta: solo se incluye si hay deducibles incompletos. Cero alertas
        // = layout limpio, sin sección que distraiga.
        if ($s->hasIncompleteWarnings()) {
            $this->layout[] = ['type' => self::ROW_ALERT, 'label' => '⚠ Deducibles incompletos (revisar antes de declarar)', 'value' => $s->deduciblesIncompletosCount];
        }

        $this->layout[] = ['type' => self::ROW_SPACER, 'label' => '', 'value' => ''];

        // ── No deducibles (gasto puro) ─────────────────────
        $this->layout[] = ['type' => self::ROW_SECTION_HEADER, 'label' => 'NO DEDUCIBLES (gasto puro)', 'value' => ''];
        $this->layout[] = ['type' => self::ROW_KEY_VALUE,      'label' => 'Cantidad',                  'value' => $s->noDeduciblesCount];
        $this->layout[] = ['type' => self::ROW_MONEY,          'label' => 'Monto total',               'value' => $s->noDeduciblesTotal];
        $this->layout[] = ['type' => self::ROW_SPACER,         'label' => '',                          'value' => ''];

        // ── Impacto en caja ────────────────────────────────
        $this->layout[] = ['type' => self::ROW_SECTION_HEADER, 'label' => 'IMPACTO EN CAJA',           'value' => ''];
        $this->layout[] = ['type' => self::ROW_KEY_VALUE,      'label' => 'Gastos que afectan caja',   'value' => $s->cashCount];
        $this->layout[] = ['type' => self::ROW_MONEY,          'label' => 'Monto pagado en efectivo',  'value' => $s->cashTotal];
        $this->layout[] = ['type' => self::ROW_KEY_VALUE,      'label' => 'Gastos que NO afectan caja', 'value' => $s->nonCashCount];
        $this->layout[] = ['type' => self::ROW_MONEY,          'label' => 'Monto otros métodos',       'value' => $s->nonCashTotal];
        $this->layout[] = ['type' => self::ROW_SPACER,         'label' => '',                          'value' => ''];

        // ── Desglose por categoría ─────────────────────────
        if ($s->byCategory !== []) {
            $this->layout[] = ['type' => self::ROW_SECTION_HEADER, 'label' => 'DESGLOSE POR CATEGORÍA', 'value' => ''];
            foreach ($s->byCategory as $bucket) {
                $this->layout[] = [
                    'type'  => self::ROW_MONEY,
                    'label' => "{$bucket['label']} ({$bucket['count']})",
                    'value' => $bucket['total'],
                ];
            }
            $this->layout[] = ['type' => self::ROW_SPACER, 'label' => '', 'value' => ''];
        }

        // ── Desglose por método de pago ────────────────────
        if ($s->byPaymentMethod !== []) {
            $this->layout[] = ['type' => self::ROW_SECTION_HEADER, 'label' => 'DESGLOSE POR MÉTODO DE PAGO', 'value' => ''];
            foreach ($s->byPaymentMethod as $bucket) {
                $this->layout[] = [
                    'type'  => self::ROW_MONEY,
                    'label' => "{$bucket['label']} ({$bucket['count']})",
                    'value' => $bucket['total'],
                ];
            }
            $this->layout[] = ['type' => self::ROW_SPACER, 'label' => '', 'value' => ''];
        }

        // ── Desglose por sucursal ──────────────────────────
        // En single-tenant suele haber 1-2 sucursales — es valioso para que
        // el admin compare comportamiento de gastos entre puntos de venta.
        if ($s->byEstablishment !== []) {
            $this->layout[] = ['type' => self::ROW_SECTION_HEADER, 'label' => 'DESGLOSE POR SUCURSAL', 'value' => ''];
            foreach ($s->byEstablishment as $bucket) {
                $this->layout[] = [
                    'type'  => self::ROW_MONEY,
                    'label' => "{$bucket['name']} ({$bucket['count']})",
                    'value' => $bucket['total'],
                ];
            }
        }
    }

    public function collection(): Collection
    {
        return collect($this->layout)->map(fn (array $row) => [$row['label'], $row['value']]);
    }

    public function title(): string
    {
        return 'Resumen ' . $this->report->summary->periodSlug();
    }

    public function columnWidths(): array
    {
        return [
            'A' => 50,
            'B' => 22,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                foreach ($this->layout as $index => $row) {
                    $excelRow = $index + 1; // Excel es 1-indexed

                    match ($row['type']) {
                        self::ROW_TITLE          => $this->styleTitle($sheet, $excelRow),
                        self::ROW_SECTION_HEADER => $this->styleSectionHeader($sheet, $excelRow),
                        self::ROW_MONEY          => $this->styleMoney($sheet, $excelRow),
                        self::ROW_HIGHLIGHT      => $this->styleHighlight($sheet, $excelRow),
                        self::ROW_ALERT          => $this->styleAlert($sheet, $excelRow),
                        self::ROW_KEY_VALUE,
                        self::ROW_SPACER         => null,
                    };
                }

                $lastRow = count($this->layout);

                // Valores alineados a la derecha en la columna B
                $sheet->getStyle("B1:B{$lastRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // Borde ligero en toda la tabla
                $sheet->getStyle("A1:B{$lastRow}")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFBDBDBD'));

                // Fila "Período" (primera key_value después del título) en negrita
                $periodRow = $this->findFirstRowOfType(self::ROW_KEY_VALUE);
                if ($periodRow !== null) {
                    $sheet->getStyle("A{$periodRow}:B{$periodRow}")->getFont()->setBold(true);
                }
            },
        ];
    }

    private function styleTitle($sheet, int $row): void
    {
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'bold'  => true,
                'size'  => 14,
                'color' => ['rgb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FF1A1A1A'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(28);
    }

    private function styleSectionHeader($sheet, int $row): void
    {
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FF424242'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'indent'     => 1,
            ],
        ]);
    }

    private function styleMoney($sheet, int $row): void
    {
        $sheet->getStyle("B{$row}")
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');
    }

    private function styleHighlight($sheet, int $row): void
    {
        // Verde claro → mismo color semántico que crédito fiscal en PurchaseBook.
        // El contador asocia visualmente "crédito fiscal" con verde a través de
        // todos los reportes del sistema.
        $this->styleMoney($sheet, $row);

        $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FF1B5E20'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFE8F5E9'],
            ],
        ]);
    }

    private function styleAlert($sheet, int $row): void
    {
        // Naranja claro con texto rojo oscuro → "atención requerida".
        // Mismo lenguaje de color que la columna "Estado fiscal" del Detalle
        // usa para "Deducible incompleto".
        $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFB71C1C'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFFF8E1'],
            ],
        ]);
    }

    /**
     * Retorna el número de fila Excel (1-indexed) de la primera fila del tipo dado.
     */
    private function findFirstRowOfType(string $type): ?int
    {
        foreach ($this->layout as $index => $row) {
            if ($row['type'] === $type) {
                return $index + 1;
            }
        }

        return null;
    }
}
