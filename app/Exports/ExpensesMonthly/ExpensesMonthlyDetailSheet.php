<?php

declare(strict_types=1);

namespace App\Exports\ExpensesMonthly;

use App\Services\Expenses\ExpensesMonthlyReport;
use App\Services\Expenses\ExpensesMonthlyReportEntry;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Detalle" del Reporte Mensual de Gastos.
 *
 * Una fila por gasto del período, ordenadas cronológicamente. Incluye datos
 * fiscales (RTN, # factura, CAI) cuando aplican y deja "—" en caso contrario
 * — gastos sin documento (taxi, propinas) son válidos pero no aportan
 * crédito fiscal.
 *
 * Las filas con `deducibleIncompleto = true` se resaltan en amarillo claro
 * con texto en rojo oscuro para que el contador identifique de un vistazo
 * los gastos que SAR podría rechazar en una auditoría — esos son los que
 * tiene que arreglar antes del 10 del mes siguiente (deadline de declaración).
 *
 * Mismo patrón visual que `PurchaseBookDetailSheet`:
 *   - Encabezado oscuro con texto blanco.
 *   - Borde fino en toda la tabla.
 *   - Columnas monetarias con formato `#,##0.00` y alineadas a la derecha.
 *   - ShouldAutoSize para que las columnas se ajusten al contenido.
 */
class ExpensesMonthlyDetailSheet implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles, ShouldAutoSize
{
    private int $rowCounter = 0;

    public function __construct(
        private readonly ExpensesMonthlyReport $report,
    ) {}

    public function collection(): Collection
    {
        return $this->report->entries;
    }

    public function headings(): array
    {
        return [
            '#',
            'Fecha',
            'Categoría',
            'Descripción',
            'Proveedor',
            'RTN',
            '# Factura',
            'CAI',
            'Fecha factura',
            'Subtotal',
            'ISV',
            'Total',
            'Deducible',
            'Estado fiscal',
            'Método pago',
            'Afecta caja',
            'Sucursal',
            'Registrado por',
        ];
    }

    /**
     * @param  ExpensesMonthlyReportEntry  $entry
     */
    public function map($entry): array
    {
        $this->rowCounter++;

        return [
            $this->rowCounter,
            $entry->expenseDate->format('d/m/Y'),
            $entry->categoryLabel,
            $entry->description,
            $entry->providerName ?? '—',
            $entry->providerRtn ?? '—',
            $entry->providerInvoiceNumber ?? '—',
            $entry->providerInvoiceCai ?? '—',
            $entry->providerInvoiceDate?->format('d/m/Y') ?? '—',
            (float) $entry->amountBase,
            (float) $entry->isvAmount,
            (float) $entry->amountTotal,
            $entry->deducibleLabel(),
            $entry->fiscalStatusLabel(),
            $entry->paymentMethodLabel,
            $entry->affectsCash ? 'Sí' : 'No',
            $entry->establishmentName,
            $entry->userName,
        ];
    }

    public function title(): string
    {
        return 'Detalle ' . $this->report->summary->periodSlug();
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rowCounter + 1; // +1 por la fila de encabezados

        // Formato monetario para columnas J-L (Subtotal, ISV, Total)
        $sheet->getStyle("J2:L{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        // Alinear a la derecha columnas numéricas monetarias
        $sheet->getStyle("J2:L{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Borde ligero a toda la tabla
        if ($this->rowCounter > 0) {
            $sheet->getStyle("A1:R{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFBDBDBD'));
        }

        // Resaltar filas con "Deducible incompleto" en amarillo claro + rojo
        // — son las que el contador debe revisar antes de declarar al SAR.
        for ($row = 2; $row <= $lastRow; $row++) {
            $estadoCell = $sheet->getCell("N{$row}")->getValue();
            if ($estadoCell === 'Deducible incompleto') {
                $sheet->getStyle("A{$row}:R{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('FFFFF8E1');

                $sheet->getStyle("A{$row}:R{$row}")
                    ->getFont()
                    ->getColor()
                    ->setRGB('FFB71C1C');

                $sheet->getStyle("N{$row}")
                    ->getFont()
                    ->setBold(true);
            }
        }

        // Encabezado: negrita + fondo oscuro + texto blanco
        return [
            1 => [
                'font' => [
                    'bold'  => true,
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
            ],
        ];
    }
}
