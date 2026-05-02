<?php

namespace App\Filament\Resources\Repairs\Pages;

use App\Enums\RepairPhotoPurpose;
use App\Filament\Resources\Repairs\RepairResource;
use App\Models\Customer;
use App\Models\Repair;
use App\Services\Repairs\RepairPhotoConverter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CreateRepair extends CreateRecord
{
    protected static string $resource = RepairResource::class;

    /**
     * Buffer interno para los paths temporales de fotos subidas.
     * Se llena en `mutateFormDataBeforeCreate` (antes de persistir el Repair)
     * y se procesa en `afterCreate` (cuando ya tenemos $this->record con id).
     *
     * Por qué: el FileUpload del form coloca los archivos en `tmp/repair-photos/`
     * con un UUID. No podemos guardarlos directamente en el directorio final
     * `repairs/{id}/` porque al momento del upload todavía no existe el id.
     *
     * @var array<int, string>
     */
    private array $pendingPhotoPaths = [];

    /**
     * Pre-procesa el form data antes de crear el Repair.
     *
     * Tres tareas:
     *   1. Auto-crear Customer si trae RTN nuevo (lógica original).
     *   2. Extraer las fotos del array (upload_photos) al buffer interno
     *      — NO deben llegar a `Repair::create()` porque no son columnas.
     *   3. Devolver el data limpio.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Auto-creación de Customer
        if (empty($data['customer_id']) && filled($data['customer_rtn'] ?? null)) {
            $customer = Customer::firstOrCreate(
                ['rtn' => $data['customer_rtn']],
                [
                    'name' => $data['customer_name'],
                    'phone' => $data['customer_phone'] ?? null,
                    'is_active' => true,
                ]
            );
            $data['customer_id'] = $customer->id;
        }

        // 2. Extraer fotos al buffer (no son columnas del Repair)
        if (! empty($data['upload_photos']) && is_array($data['upload_photos'])) {
            $this->pendingPhotoPaths = array_values($data['upload_photos']);
        }
        unset($data['upload_photos']);

        return $data;
    }

    /**
     * Tras crear el Repair, procesar las fotos pendientes:
     *   - Convertir cada una a WebP optimizado (resize a 1920px, calidad 80).
     *   - Mover de `tmp/repair-photos/` a `repairs/{repair_id}/`.
     *   - Crear el registro RepairPhoto vinculado.
     *
     * Si una foto falla la conversión (archivo corrupto, formato no soportado),
     * se loguea pero no se aborta la creación del Repair — el usuario puede
     * reintentar la subida desde el RelationManager.
     */
    protected function afterCreate(): void
    {
        if (empty($this->pendingPhotoPaths)) {
            return;
        }

        /** @var Repair $repair */
        $repair = $this->record;
        $converter = app(RepairPhotoConverter::class);
        $disk = Storage::disk('public');
        $destDirectory = "repairs/{$repair->id}";

        foreach ($this->pendingPhotoPaths as $tmpPath) {
            try {
                $finalPath = $converter->convertToWebp(
                    sourcePath: $tmpPath,
                    destDirectory: $destDirectory,
                );

                $repair->photos()->create([
                    'photo_path' => $finalPath,
                    'purpose' => RepairPhotoPurpose::Recepcion,
                    'file_size' => $disk->exists($finalPath) ? $disk->size($finalPath) : null,
                    'uploaded_by' => Auth::id(),
                ]);
            } catch (\Throwable $e) {
                // No abortar la creación: log y seguir. El cajero puede
                // reintentar desde la pestaña "Fotos del equipo" en Edit.
                logger()->warning('RepairPhoto conversion failed', [
                    'repair_id' => $repair->id,
                    'tmp_path' => $tmpPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
