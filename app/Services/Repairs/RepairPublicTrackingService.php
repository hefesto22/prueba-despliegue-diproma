<?php

namespace App\Services\Repairs;

use App\Enums\RepairPhotoPurpose;
use App\Enums\RepairStatus;
use App\Models\CompanySetting;
use App\Models\Repair;

/**
 * Prepara el payload de la vista pública de tracking de una Reparación.
 *
 * El cliente escanea el QR del recibo desde su celular y aterriza en
 * `/r/{qr_token}`. Esta vista es una página simple con:
 *   - Header con número + estado actual visualmente prominente.
 *   - Timeline de fases del flujo (Recibido → ... → Entregada).
 *   - Datos del equipo (lo que el cliente trajo).
 *   - Fotos del equipo (galería simple).
 *   - Total + saldo pendiente.
 *   - Botón al recibo de cotización imprimible si lo necesita.
 *
 * NO muestra:
 *   - Notas internas del staff.
 *   - Diagnóstico técnico (a menos que ya esté cotizado y aprobado).
 *   - Datos de costos / margen interno.
 *
 * SRP: solo arma el payload. La vista Blade lo renderiza.
 */
class RepairPublicTrackingService
{
    /**
     * @return array<string, mixed>
     */
    public function buildPayload(Repair $repair): array
    {
        $repair->loadMissing([
            'deviceCategory:id,name,icon',
            'photos:id,repair_id,photo_path,purpose,caption',
            'items',
        ]);

        return [
            'repair' => $repair,
            'company' => $this->companyBlock(),
            'currentStatus' => $repair->status,
            'timeline' => $this->buildTimeline($repair),
            'device' => [
                'category' => $repair->deviceCategory?->name ?? '—',
                'icon' => $repair->deviceCategory?->icon ?? 'heroicon-o-device-tablet',
                'brand' => $repair->device_brand,
                'model' => $repair->device_model,
                'serial' => $repair->device_serial,
                'reported_issue' => $repair->reported_issue,
                'diagnosis' => $repair->diagnosis,
            ],
            'photos' => $this->mapPhotos($repair),
            'totals' => [
                'has_quotation' => $repair->items->isNotEmpty(),
                'total' => number_format((float) $repair->total, 2),
                'advance_payment' => number_format((float) $repair->advance_payment, 2),
                'outstanding' => number_format(
                    max(0, (float) $repair->total - (float) $repair->advance_payment),
                    2,
                ),
                'has_advance' => (float) $repair->advance_payment > 0,
            ],
            'printUrl' => route('repairs.quotation.print', ['repair' => $repair->qr_token]),
        ];
    }

    /**
     * Construye la línea de tiempo del flujo de la reparación.
     *
     * Cada fase tiene un timestamp si ya ocurrió, o null si está pendiente.
     * El front-end usa esto para pintar el progreso visual (check, en proceso, pendiente).
     *
     * Solo se incluyen las fases del flujo principal (Recibido → Entregada).
     * Los estados terminales alternativos (Rechazada, Anulada, Abandonada)
     * se manejan como un "fin alternativo" en la vista.
     *
     * @return array<int, array{key: string, label: string, at: ?string, reached: bool}>
     */
    private function buildTimeline(Repair $repair): array
    {
        $phases = [
            ['key' => 'received', 'label' => 'Recibido', 'at' => $repair->received_at],
            ['key' => 'quoted', 'label' => 'Cotizado', 'at' => $repair->quoted_at],
            ['key' => 'approved', 'label' => 'Aprobado', 'at' => $repair->approved_at],
            ['key' => 'in_repair', 'label' => 'En reparación', 'at' => $repair->repair_started_at],
            ['key' => 'ready', 'label' => 'Listo para entrega', 'at' => $repair->completed_at],
            ['key' => 'delivered', 'label' => 'Entregado', 'at' => $repair->delivered_at],
        ];

        return array_map(static fn (array $phase): array => [
            'key' => $phase['key'],
            'label' => $phase['label'],
            'at' => $phase['at']?->format('d/m/Y H:i'),
            'reached' => $phase['at'] !== null,
        ], $phases);
    }

    /**
     * @return array<int, array{url: string, caption: ?string, purpose_label: string}>
     */
    private function mapPhotos(Repair $repair): array
    {
        return $repair->photos->map(fn ($photo) => [
            'url' => $photo->url,
            'caption' => $photo->caption,
            'purpose_label' => $photo->purpose?->getLabel() ?? '',
        ])->all();
    }

    private function companyBlock(): array
    {
        $cs = CompanySetting::query()->first();

        return [
            'name' => $cs?->name ?? config('app.name'),
            'phone' => $cs?->phone ?? '',
            'address' => $cs?->address ?? '',
        ];
    }
}
