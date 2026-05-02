<?php

namespace App\Models;

use App\Enums\RepairPhotoPurpose;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Foto del equipo asociada a una Reparación.
 *
 * El borrado físico del archivo lo hace `RepairPhotoCleanupService` (F-R6),
 * NO el modelo. Aquí no se sobrecarga el evento `deleting` para evitar que
 * un soft-delete accidental del Repair borre las fotos del disco
 * (los Repairs son `softDeletes`; las fotos NO).
 */
class RepairPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'repair_id',
        'photo_path',
        'purpose',
        'caption',
        'file_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => RepairPhotoPurpose::class,
            'file_size' => 'integer',
        ];
    }

    // ─── Relaciones ──────────────────────────────────────────

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ─── Helpers ─────────────────────────────────────────────

    /** URL pública del archivo en el disk 'public'. */
    public function getUrlAttribute(): ?string
    {
        if (empty($this->photo_path)) {
            return null;
        }
        return Storage::disk('public')->url($this->photo_path);
    }

    /** ¿El archivo físico todavía existe en el disk? */
    public function fileExists(): bool
    {
        return ! empty($this->photo_path)
            && Storage::disk('public')->exists($this->photo_path);
    }
}
