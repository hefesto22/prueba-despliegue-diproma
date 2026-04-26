<?php

namespace App\Exports;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exportar movimientos de kardex a Excel.
 *
 * Usa FromQuery + chunkById internamente — nunca carga toda la tabla en memoria.
 * Puede exportar cualquier subset de inventory_movements pasando el Builder
 * correspondiente (global, por producto, con filtros de fecha, etc.).
 *
 * Para volúmenes grandes (>5000 filas) cambiar a ShouldQueue + WithChunkReading.
 */
class KardexExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithTitle, WithStyles
{
    public function __construct(
        private readonly Builder|EloquentBuilder $baseQuery,
        private readonly ?string $titleSuffix = null,
    ) {}

    public function query(): Builder|EloquentBuilder
    {
        return $this->baseQuery
            ->with(['product:id,name,sku', 'createdBy:id,name']);
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'SKU',
            'Producto',
            'Tipo',
            'Cantidad',
            'Costo Unit. (L.)',
            'Valor Mov. (L.)',
            'Stock Antes',
            'Stock Después',
            'Notas',
            'Responsable',
        ];
    }

    public function map($movement): array
    {
        return [
            $movement->created_at->format('d/m/Y H:i'),
            $movement->product?->sku ?? '—',
            $movement->product?->name ?? '—',
            $movement->type->getLabel(),
            $movement->quantity,
            $movement->unit_cost !== null ? (float) $movement->unit_cost : null,
            $movement->total_value,
            $movement->stock_before,
            $movement->stock_after,
            $movement->notes,
            $movement->createdBy?->name ?? 'Sistema',
        ];
    }

    public function title(): string
    {
        return 'Kardex' . ($this->titleSuffix ? ' - ' . substr($this->titleSuffix, 0, 20) : '');
    }

    public function styles(Worksheet $sheet): array
    {
        // Formato monetario para columnas F y G (Costo Unit. y Valor Mov.)
        $sheet->getStyle('F:G')
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
