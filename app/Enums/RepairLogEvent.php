<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de evento registrado en `repair_status_logs`.
 *
 * Cada cambio relevante en una Reparación (transición de estado, edición de
 * líneas, cobro/devolución de anticipo, foto agregada/eliminada) deja un
 * registro auditado con su tipo de evento, who/when, y metadata estructurada.
 *
 * El campo `metadata` (JSON) lleva contexto específico por evento:
 *   - status_change:    {"from": "cotizado", "to": "aprobado"}
 *   - items_added:      {"item_ids": [12, 13], "delta_total": "350.00"}
 *   - items_removed:    {"item_ids": [9], "delta_total": "-120.00"}
 *   - advance_paid:     {"amount": "500.00", "cash_movement_id": 4521}
 *   - advance_refunded: {"amount": "500.00", "cash_movement_id": 4533}
 *   - advance_to_credit:{"amount": "500.00", "customer_credit_id": 87}
 *   - photo_added:      {"photo_id": 22, "purpose": "recepcion"}
 *   - photo_deleted:    {"photo_ids": [22, 23], "reason": "cleanup_after_delivery"}
 *
 * Diseño: el log es WORM (write-once-read-many). NUNCA se actualizan
 * registros existentes; cada evento es una fila nueva.
 */
enum RepairLogEvent: string implements HasLabel, HasColor, HasIcon
{
    case StatusChange = 'status_change';
    case ItemsAdded = 'items_added';
    case ItemsRemoved = 'items_removed';
    case ItemsModified = 'items_modified';
    case AdvancePaid = 'advance_paid';
    case AdvanceRefunded = 'advance_refunded';
    case AdvanceToCredit = 'advance_to_credit';
    case PhotoAdded = 'photo_added';
    case PhotoDeleted = 'photo_deleted';
    case TechnicianAssigned = 'technician_assigned';
    case DiagnosisUpdated = 'diagnosis_updated';

    public function getLabel(): string
    {
        return match ($this) {
            self::StatusChange => 'Cambio de estado',
            self::ItemsAdded => 'Líneas agregadas',
            self::ItemsRemoved => 'Líneas eliminadas',
            self::ItemsModified => 'Líneas modificadas',
            self::AdvancePaid => 'Anticipo cobrado',
            self::AdvanceRefunded => 'Anticipo devuelto',
            self::AdvanceToCredit => 'Anticipo a crédito',
            self::PhotoAdded => 'Foto agregada',
            self::PhotoDeleted => 'Foto eliminada',
            self::TechnicianAssigned => 'Técnico asignado',
            self::DiagnosisUpdated => 'Diagnóstico actualizado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::StatusChange => 'primary',
            self::ItemsAdded => 'success',
            self::ItemsRemoved => 'danger',
            self::ItemsModified => 'warning',
            self::AdvancePaid => 'info',
            self::AdvanceRefunded => 'danger',
            self::AdvanceToCredit => 'warning',
            self::PhotoAdded => 'gray',
            self::PhotoDeleted => 'gray',
            self::TechnicianAssigned => 'info',
            self::DiagnosisUpdated => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::StatusChange => 'heroicon-o-arrows-right-left',
            self::ItemsAdded => 'heroicon-o-plus-circle',
            self::ItemsRemoved => 'heroicon-o-minus-circle',
            self::ItemsModified => 'heroicon-o-pencil-square',
            self::AdvancePaid => 'heroicon-o-banknotes',
            self::AdvanceRefunded => 'heroicon-o-arrow-uturn-left',
            self::AdvanceToCredit => 'heroicon-o-credit-card',
            self::PhotoAdded => 'heroicon-o-photo',
            self::PhotoDeleted => 'heroicon-o-trash',
            self::TechnicianAssigned => 'heroicon-o-user-plus',
            self::DiagnosisUpdated => 'heroicon-o-clipboard-document-list',
        };
    }
}
