<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Estados del ciclo de vida de una Reparación.
 *
 * Flujo principal:
 *   Recibido → Cotizado → Aprobado → EnReparacion → ListoEntrega → Entregada
 *
 * Estados terminales alternativos:
 *   - Rechazada:  cliente no aprobó la cotización (post-Cotizado).
 *   - Anulada:    anulación administrativa antes de Entregar.
 *   - Abandonada: cliente nunca recogió tras 60 días en ListoEntrega
 *                 (marcado automático por job programado).
 *
 * Solo el paso a `Entregada` emite Factura CAI + descuenta stock + ingreso caja.
 * Cualquier rectificación post-Entregada se hace vía CreditNote (ya existente).
 *
 * El método `canTransitionTo()` actúa como guardián para `RepairService`,
 * evitando saltos ilegales (ej: Recibido → Entregada directo, que rompería
 * la auditoría de cambios y la trazabilidad de cobros de anticipo).
 */
enum RepairStatus: string implements HasLabel, HasColor, HasIcon
{
    case Recibido = 'recibido';
    case Cotizado = 'cotizado';
    case Aprobado = 'aprobado';
    case Rechazada = 'rechazada';
    case EnReparacion = 'en_reparacion';
    case ListoEntrega = 'listo_entrega';
    case Entregada = 'entregada';
    case Abandonada = 'abandonada';
    case Anulada = 'anulada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recibido => 'Recibido',
            self::Cotizado => 'Cotizado',
            self::Aprobado => 'Aprobado',
            self::Rechazada => 'Rechazada',
            self::EnReparacion => 'En reparación',
            self::ListoEntrega => 'Listo para entrega',
            self::Entregada => 'Entregada',
            self::Abandonada => 'Abandonada',
            self::Anulada => 'Anulada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Recibido => 'gray',
            self::Cotizado => 'info',
            self::Aprobado => 'warning',
            self::Rechazada => 'danger',
            self::EnReparacion => 'primary',
            self::ListoEntrega => 'success',
            self::Entregada => 'success',
            self::Abandonada => 'danger',
            self::Anulada => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Recibido => 'heroicon-o-inbox-arrow-down',
            self::Cotizado => 'heroicon-o-document-text',
            self::Aprobado => 'heroicon-o-check-badge',
            self::Rechazada => 'heroicon-o-no-symbol',
            self::EnReparacion => 'heroicon-o-wrench-screwdriver',
            self::ListoEntrega => 'heroicon-o-bell-alert',
            self::Entregada => 'heroicon-o-truck',
            self::Abandonada => 'heroicon-o-archive-box-x-mark',
            self::Anulada => 'heroicon-o-x-circle',
        };
    }

    /** Estados terminales: ya no admiten transiciones. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Entregada, self::Rechazada, self::Abandonada, self::Anulada => true,
            default => false,
        };
    }

    /** ¿La reparación todavía está activa en el flujo del taller? */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /** ¿Permite editar líneas (cotización todavía abierta)? */
    public function allowsItemsEdit(): bool
    {
        return match ($this) {
            self::Recibido, self::Cotizado, self::Aprobado, self::EnReparacion => true,
            default => false,
        };
    }

    /** ¿Permite cobrar/recibir anticipo? */
    public function allowsAdvancePayment(): bool
    {
        return match ($this) {
            self::Cotizado, self::Aprobado => true,
            default => false,
        };
    }

    /**
     * Validador de transición de estado.
     *
     * Diseño: lista blanca explícita por estado origen. Cualquier transición
     * no listada es ilegal y `RepairService` debe lanzar excepción.
     *
     * No se modela "regresar a estado anterior" — si hay un error, se anula
     * y se crea una nueva reparación, manteniendo trazabilidad SAR.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    /** @return list<self> */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Recibido => [self::Cotizado, self::Anulada],
            self::Cotizado => [self::Aprobado, self::Rechazada, self::Anulada],
            self::Aprobado => [self::EnReparacion, self::Anulada],
            self::EnReparacion => [self::ListoEntrega, self::Anulada],
            self::ListoEntrega => [self::Entregada, self::Abandonada, self::Anulada],
            // Terminales: sin transiciones posibles.
            self::Entregada, self::Rechazada, self::Abandonada, self::Anulada => [],
        };
    }

    /** Estados que el cliente puede ver desde la URL pública del QR. */
    public function isPublicVisible(): bool
    {
        return $this !== self::Anulada;
    }
}
