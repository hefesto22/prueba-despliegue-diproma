<?php

namespace App\Exports\SalesBook;

use App\Services\FiscalBooks\SalesBook;
use App\Services\FiscalBooks\SalesBookEntry;
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
 * Hoja "Detalle" del Libro de Ventas SAR.
 *
 * Una fila por cada factura (tipo 01) y nota de crédito (tipo 03) del período,
 * ordenadas cronológicamente. Las anuladas aparecen en el detalle con la
 * columna Estado marcada (obligación SAR: el correlativo debe verse completo
 * sin huecos) pero NO suman en los totales del resumen.
 */
class SalesBookDetailSheet implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles, ShouldAutoSize
{
    private int $rowCounter = 0;

    public function __construct(
        private readonly SalesBook $book,
    ) {}

    public function collection(): Collection
    {
        return $this->book->entries;
    }

    public function headings(): array
    {
        return [
            '#',
            'Fecha',
            'Tipo',
            'Número',
            'CAI',
            'RTN Emisor',
            'RTN Receptor',
            'Nombre Receptor',
            'Exento',
            'Gravado 15%',
            'ISV 15%',
            'Total',
            'Estado',
            'Ref. Origen',
        ];
    }

    /**
     * @param  SalesBookEntry  $entry
     */
    public function map($entry): array
    {
        $this->rowCounter++;

        return [
            $this->rowCounter,
            $entry->fecha->format('d/m/Y'),
            $entry->tipoLabel(),
            $entry->numero,
            $entry->cai ?? '—',
            $entry->rtnEmisor,
            $entry->rtnReceptor,
            $entry->nombreReceptor,
            (float) $entry->exento,
            (float) $entry->gravado,
            (float) $entry->isv,
            (float) $entry->total,
            $entry->estadoLabel(),
            $entry->referenciaOrigen ?? '—',
        ];
    }

    public function title(): string
    {
        return 'Detalle ' . $this->book->summary->periodSlug();
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->rowCounter + 1; // +1 por la fila de encabezados

        // Formato monetario para columnas I-L (Exento, Gravado, ISV, Total)
        $sheet->getStyle("I2:L{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        // Alinear a la derecha columnas numéricas monetarias
        $sheet->getStyle("I2:L{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Borde ligero a toda la tabla
        if ($this->rowCounter > 0) {
            $sheet->getStyle("A1:N{$lastRow}")
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFBDBDBD'));
        }

        // Resaltar filas anuladas con fondo gris tenue y texto tachado
        for ($row = 2; $row <= $lastRow; $row++) {
            $estadoCell = $sheet->getCell("M{$row}")->getValue();
            if ($estadoCell === 'Anulada') {
                $sheet->getStyle("A{$row}:N{$row}")
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('FFF5F5F5');

                $sheet->getStyle("A{$row}:N{$row}")
                    ->getFont()
                    ->setStrikethrough(true)
                    ->getColor()
                    ->setRGB('FF9E9E9E');
            }
        }

        // Encabezado: negrita + fondo oscuro + texto blanco
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FF1A1A1A'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
}
