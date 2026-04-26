<?php

namespace App\Exports\SalesBook;

use App\Services\FiscalBooks\SalesBook;
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
 * Hoja "Resumen" del Libro de Ventas SAR.
 *
 * Totales del período que el contador usa para llenar la declaración ISV
 * (Formulario ISV-353) en el portal eSAR.
 *
 * Diseño: construcción basada en un layout declarativo. Cada fila se registra
 * con su tipo (title / sectionHeader / keyValue / moneyKeyValue / highlight /
 * spacer) y eso decide su número de fila Y su estilo. Así si se agregan o
 * reordenan filas, los estilos siguen apuntando al lugar correcto — no hay
 * números de fila mágicos esparcidos por registerEvents().
 */
class SalesBookSummarySheet implements FromCollection, WithTitle, WithEvents, WithColumnWidths, ShouldAutoSize
{
    // Tipos de fila — gobiernan rendering Y estilo.
    private const ROW_TITLE          = 'title';          // título principal, fondo oscuro, merge
    private const ROW_SECTION_HEADER = 'section_header'; // encabezado de sección, fondo medio, merge
    private const ROW_KEY_VALUE      = 'key_value';      // concepto | valor (entero)
    private const ROW_MONEY          = 'money';          // concepto | valor monetario (#,##0.00)
    private const ROW_HIGHLIGHT      = 'highlight';      // fila destacada (ej: ISV neto)
    private const ROW_SPACER         = 'spacer';         // fila vacía

    /**
     * Layout declarativo. La única fuente de verdad para rendering + estilos.
     *
     * @var list<array{type: string, label: string, value: int|float|string}>
     */
    private array $layout = [];

    public function __construct(
        private readonly SalesBook $book,
    ) {
        $this->buildLayout();
    }

    private function buildLayout(): void
    {
        $s = $this->book->summary;

        $this->layout = [
            ['type' => self::ROW_TITLE,          'label' => 'LIBRO DE VENTAS — RESUMEN', 'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Período',                    'value' => $s->periodLabel()],
            ['type' => self::ROW_SPACER,         'label' => '',                           'value' => ''],

            ['type' => self::ROW_SECTION_HEADER, 'label' => 'FACTURAS (Tipo 01)',         'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Emitidas',                   'value' => $s->facturasEmitidasCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Vigentes',                   'value' => $s->facturasVigentesCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Anuladas',                   'value' => $s->facturasAnuladasCount],
            ['type' => self::ROW_MONEY,          'label' => 'Exento',                     'value' => $s->facturasExento],
            ['type' => self::ROW_MONEY,          'label' => 'Gravado 15%',                'value' => $s->facturasGravado],
            ['type' => self::ROW_MONEY,          'label' => 'ISV 15%',                    'value' => $s->facturasIsv],
            ['type' => self::ROW_MONEY,          'label' => 'Total facturas vigentes',    'value' => $s->facturasTotal],
            ['type' => self::ROW_SPACER,         'label' => '',                           'value' => ''],

            ['type' => self::ROW_SECTION_HEADER, 'label' => 'NOTAS DE CRÉDITO (Tipo 03)', 'value' => ''],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Emitidas',                   'value' => $s->notasCreditoEmitidasCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Vigentes',                   'value' => $s->notasCreditoVigentesCount],
            ['type' => self::ROW_KEY_VALUE,      'label' => 'Anuladas',                   'value' => $s->notasCreditoAnuladasCount],
            ['type' => self::ROW_MONEY,          'label' => 'Exento',                     'value' => $s->notasCreditoExento],
            ['type' => self::ROW_MONEY,          'label' => 'Gravado 15%',                'value' => $s->notasCreditoGravado],
            ['type' => self::ROW_MONEY,          'label' => 'ISV 15%',                    'value' => $s->notasCreditoIsv],
            ['type' => self::ROW_MONEY,          'label' => 'Total notas de crédito vigentes', 'value' => $s->notasCreditoTotal],
            ['type' => self::ROW_SPACER,         'label' => '',                           'value' => ''],

            ['type' => self::ROW_SECTION_HEADER, 'label' => 'NETO DEL PERÍODO (para declaración ISV-353)', 'value' => ''],
            ['type' => self::ROW_MONEY,          'label' => 'Exento neto',                'value' => $s->exentoNeto()],
            ['type' => self::ROW_MONEY,          'label' => 'Gravado neto',               'value' => $s->gravadoNeto()],
            ['type' => self::ROW_HIGHLIGHT,      'label' => 'ISV neto a declarar',        'value' => $s->isvNeto()],
            ['type' => self::ROW_MONEY,          'label' => 'Venta neta total',           'value' => $s->ventaNeta()],
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
        // Monetario + resaltado (fondo azul claro, texto azul oscuro negrita)
        $this->styleMoney($sheet, $row);

        $sheet->getStyle("A{$row}:B{$row}")->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FF0D47A1'],
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFE3F2FD'],
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
