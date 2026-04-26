<?php

namespace App\Exports\PurchaseBook;

use App\Services\FiscalBooks\PurchaseBook;
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
 * Hoja "Resumen" del Libro de Compras SAR.
 *
 * Totales del período que el contador usa para llenar la declaración ISV
 * (Formulario ISV-353) en el portal eSAR, específicamente el cuadro
 * "Crédito fiscal" — a diferencia del Libro de Ventas que alimenta el
 * "Débito fiscal".
 *
 * Diferencias con SalesBookSummarySheet:
 *   - Tiene 3 secciones de documento (Facturas 01, NC 03, ND 04) vs. 2
 *     en ventas (Facturas + NC únicamente, porque Diproma todavía no emite ND).
 *   - La fila destacada es "Crédito fiscal neto" en vez de "ISV neto a declarar".
 *   - La fórmula del neto combina 3 tipos: F + ND − NC.
 *
 * Se mantiene el layout declarativo: cada fila se registra con su tipo y
 * eso decide su número de fila Y su estilo. Agregar o reordenar filas no
 * requiere tocar registerEvents() — los estilos siguen al layout.
 */
class PurchaseBookSummarySheet implements FromCollection, WithTitle, WithEvents, WithColumnWidths, ShouldAutoSize
{
    // Tipos de fila — gobiernan rendering Y estilo.
    private const ROW_TITLE          = 'title';          // título principal, fondo oscuro, merge
    private const ROW_SECTION_HEADER = 'section_header'; // encabezado de sección, fondo medio, merge
    private const ROW_KEY_VALUE      = 'key_value';      // concepto | valor (entero)
    private const ROW_MONEY          = 'money';          // concepto | valor monetario (#,##0.00)
    private const ROW_HIGHLIGHT      = 'highlight';      // fila destacada (ej: crédito fiscal neto)
    private const ROW_SPACER         = 'spacer';         // fila vacía

    /**
     * Layout declarativo. La única fuente de verdad para rendering + estilos.
     *
     * @var list<array{type: string, label: string, value: int|float|string}>
     */
    private array $layout = [];

    public function __construct(
        private readonly PurchaseBook $book,
    ) {
        $this->buildLayout();
    }

    private function buildLayout(): void
    {
        $s = $this->book->summary;

        $this->layout = [
            ['type' => self::ROW_TITLE,          'label' => 'LIBRO DE COMPRAS — RESUMEN', 'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Período',                    'value' => $s->periodLabel()],
            ['type' => self::ROW_SPACER,         'label' => '',                           'value' => ''],

            ['type' => self::ROW_SECTION_HEADER, 'label' => 'FACTURAS (Tipo 01)',         'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Recibidas',                  'value' => $s->facturasEmitidasCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Vigentes',                   'value' => $s->facturasVigentesCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Anuladas',                   'value' => $s->facturasAnuladasCount],
            ['type' => self::ROW_MONEY,          'label' => 'Exento',                     'value' => $s->facturasExento],
            ['type' => self::ROW_MONEY,          'label' => 'Gravado 15%',                'value' => $s->facturasGravado],
            ['type' => self::ROW_MONEY,          'label' => 'ISV 15%',                    'value' => $s->facturasIsv],
            ['type' => self::ROW_MONEY,          'label' => 'Total facturas vigentes',    'value' => $s->facturasTotal],
            ['type' => self::ROW_SPACER,         'label' => '',                           'value' => ''],

            ['type' => self::ROW_SECTION_HEADER, 'label' => 'NOTAS DE CRÉDITO (Tipo 03)', 'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Recibidas',                  'value' => $s->notasCreditoEmitidasCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Vigentes',                   'value' => $s->notasCreditoVigentesCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Anuladas',                   'value' => $s->notasCreditoAnuladasCount],
            ['type' => self::ROW_MONEY,          'label' => 'Exento',                     'value' => $s->notasCreditoExento],
            ['type' => self::ROW_MONEY,          'label' => 'Gravado 15%',                'value' => $s->notasCreditoGravado],
            ['type' => self::ROW_MONEY,          'label' => 'ISV 15%',                    'value' => $s->notasCreditoIsv],
            ['type' => self::ROW_MONEY,          'label' => 'Total notas de crédito vigentes', 'value' => $s->notasCreditoTotal],
            ['type' => self::ROW_SPACER,         'label' => '',                           'value' => ''],

            ['type' => self::ROW_SECTION_HEADER, 'label' => 'NOTAS DE DÉBITO (Tipo 04)',  'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Recibidas',                  'value' => $s->notasDebitoEmitidasCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Vigentes',                   'value' => $s->notasDebitoVigentesCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Anuladas',                   'value' => $s->notasDebitoAnuladasCount],
            ['type' => self::ROW_MONEY,          'label' => 'Exento',                     'value' => $s->notasDebitoExento],
            ['type' => self::ROW_MONEY,          'label' => 'Gravado 15%',                'value' => $s->notasDebitoGravado],
            ['type' => self::ROW_MONEY,          'label' => 'ISV 15%',                    'value' => $s->notasDebitoIsv],
            ['type' => self::ROW_MONEY,          'label' => 'Total notas de débito vigentes', 'value' => $s->notasDebitoTotal],
            ['type' => self::ROW_SPACER,         'label' => '',                           'value' => ''],

            ['type' => self::ROW_SECTION_HEADER, 'label' => 'NETO DEL PERÍODO (para declaración ISV-353)', 'value' => ''],
            ['type' => self::ROW_MONEY,          'label' => 'Exento neto',                'value' => $s->exentoNeto()],
            ['type' => self::ROW_MONEY,          'label' => 'Gravado neto',               'value' => $s->gravadoNeto()],
            ['type' => self::ROW_HIGHLIGHT,      'label' => 'Crédito fiscal neto',        'value' => $s->creditoFiscalNeto()],
            ['type' => self::ROW_MONEY,          'label' => 'Compra neta total',          'value' => $s->compraNeta()],
        ];
    }

    public function collection(): Collection
    {
        return collect($this->layout)->map(fn (array $row) => [$row['label'], $row['value']]);
    }

    public function title(): string
    {
        return 'Resumen ' . $this->book->summary->periodSlug();
    }

    public function columnWidths(): array
    {
        return [
            'A' => 45,
            'B' => 22,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Los números de fila se derivan del layout declarativo —
                // si se agrega/reordena una fila, los estilos siguen correctos.
                foreach ($this->layout as $index => $row) {
                    $excelRow = $index + 1; // Excel es 1-indexed

                    match ($row['type']) {
                        self::ROW_TITLE          => $this->styleTitle($sheet, $excelRow),
                        self::ROW_SECTION_HEADER => $this->styleSectionHeader($sheet, $excelRow),
                        self::ROW_MONEY          => $this->styleMoney($sheet, $excelRow),
                        self::ROW_HIGHLIGHT      => $this->styleHighlight($sheet, $excelRow),
                        self::ROW_KEY_VALUE,
                        self::ROW_SPACER         => null, // estilo base sin modificaciones
                    };
                }

                // Valores alineados a la derecha en la columna B para todas las filas
                $lastRow = count($this->layout);
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
        // Monetario + resaltado (fondo verde claro, texto verde oscuro negrita)
        // Diferencia con ventas: verde en vez de azul, para que el contador
        // distingue visualmente crédito fiscal (compras) de débito fiscal (ventas).
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
