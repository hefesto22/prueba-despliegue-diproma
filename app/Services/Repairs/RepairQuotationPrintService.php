<?php

namespace App\Services\Repairs;

use App\Models\CompanySetting;
use App\Models\Repair;
use App\Services\Fiscal\FiscalQrService;

/**
 * Prepara los datos del Recibo Interno de Cotización para la vista de impresión.
 *
 * Análogo a `InvoicePrintService` pero para reparaciones:
 *   - NO es un documento fiscal CAI (no requiere integrity_hash sellado).
 *   - Usa `qr_token` del repair como identificador público en el QR.
 *   - El QR apunta a la URL pública del repair (`/r/{qr_token}`) que el
 *     cliente puede consultar desde su celular para ver estado + fotos.
 *
 * SRP: solo orquesta carga + formato. No calcula fiscalidad ni persiste.
 */
class RepairQuotationPrintService
{
    /**
     * Prefijo de la URL pública para escanear el QR del recibo.
     * Debe coincidir con la ruta registrada en `routes/web.php` como `repairs.public.show`.
     */
    private const PUBLIC_PATH_PREFIX = 'r';

    public function __construct(
        private readonly FiscalQrService $qr,
    ) {}

    /**
     * Retorna el payload completo para la vista de impresión.
     *
     * @return array<string, mixed>
     */
    public function buildPrintPayload(Repair $repair): array
    {
        $repair->loadMissing([
            'customer:id,name,phone,rtn',
            'deviceCategory:id,name',
            'technician:id,name',
            'createdBy:id,name',
            'items',
        ]);

        $company = $this->buildCompanyBlock();

        return [
            'repair' => $repair,
            'company' => $company,
            'items' => $this->mapItemsForView($repair),
            'totals' => $this->buildTotals($repair),
            'customer' => $this->buildCustomerBlock($repair),
            'device' => $this->buildDeviceBlock($repair),
            'qrSvg' => $this->qr->generateSvg(
                hash: $repair->qr_token,
                pathPrefix: self::PUBLIC_PATH_PREFIX,
            ),
            'qrUrl' => $this->qr->buildVerificationUrl(
                hash: $repair->qr_token,
                pathPrefix: self::PUBLIC_PATH_PREFIX,
            ),
            'receivedBy' => $repair->createdBy?->name ?? '—',
            'technician' => $repair->technician?->name ?? 'Sin asignar',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapItemsForView(Repair $repair): array
    {
        return $repair->items->map(function ($item) {
            return [
                'description' => $item->description,
                'source_label' => $item->source?->getLabel() ?? '',
                'condition_label' => $item->condition?->getLabel(),
                'quantity' => number_format((float) $item->quantity, 2),
                'unit_price' => number_format((float) $item->unit_price, 2),
                'tax_label' => $item->tax_type?->getLabel() ?? '',
                'subtotal' => number_format((float) $item->subtotal, 2),
                'isv' => number_format((float) $item->isv_amount, 2),
                'total' => number_format((float) $item->total, 2),
            ];
        })->all();
    }

    private function buildTotals(Repair $repair): array
    {
        $outstanding = (float) $repair->total - (float) $repair->advance_payment;

        return [
            'exempt_total' => number_format((float) $repair->exempt_total, 2),
            'taxable_total' => number_format((float) $repair->taxable_total, 2),
            'subtotal' => number_format((float) $repair->subtotal, 2),
            'isv' => number_format((float) $repair->isv, 2),
            'total' => number_format((float) $repair->total, 2),
            'advance_payment' => number_format((float) $repair->advance_payment, 2),
            'outstanding' => number_format($outstanding, 2),
            'has_advance' => (float) $repair->advance_payment > 0,
        ];
    }

    private function buildCustomerBlock(Repair $repair): array
    {
        return [
            'name' => $repair->customer_name,
            'phone' => $repair->customer_phone,
            'rtn' => $repair->customer_rtn ?? '—',
            'has_rtn' => filled($repair->customer_rtn),
        ];
    }

    private function buildDeviceBlock(Repair $repair): array
    {
        return [
            'category' => $repair->deviceCategory?->name ?? '—',
            'brand' => $repair->device_brand,
            'model' => $repair->device_model ?? '—',
            'serial' => $repair->device_serial ?? '—',
            'reported_issue' => $repair->reported_issue,
            'diagnosis' => $repair->diagnosis ?? 'Pendiente',
        ];
    }

    private function buildCompanyBlock(): array
    {
        $cs = CompanySetting::query()->first();

        return [
            'name' => $cs?->name ?? config('app.name'),
            'rtn' => $cs?->rtn ?? '',
            'address' => $cs?->address ?? '',
            'phone' => $cs?->phone ?? '',
            'email' => $cs?->email ?? '',
        ];
    }
}
