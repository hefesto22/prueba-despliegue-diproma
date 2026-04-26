<?php

namespace App\Services\Alerts\Enums;

/**
 * Niveles de severidad de las alertas de CAI.
 *
 * Tres tiers explícitos, mapeables a colores de Filament y a tono del email:
 *
 *   - Info     → el vencimiento o agotamiento está lejos pero dentro de la
 *                ventana de aviso más amplia. Sirve como recordatorio.
 *   - Urgent   → se acerca un umbral intermedio. Acción recomendada pronto.
 *   - Critical → uno de dos casos:
 *       1) Estamos dentro del umbral más estrecho (ej: ≤ 7 días).
 *       2) NO existe un CAI sucesor pre-registrado — independientemente del
 *          tiempo/volumen restante, el sistema se quedará sin correlativo si
 *          no se actúa.
 *
 * Este enum se mantiene como plano (sin lógica de severidad) — la decisión
 * de qué tier aplica vive en los checkers, que son los que conocen las
 * reglas de negocio.
 */
enum CaiAlertSeverity: string
{
    case Info = 'info';
    case Urgent = 'urgent';
    case Critical = 'critical';

    /**
     * Color correspondiente en la paleta de Filament (para iconos, badges
     * y notificaciones de database channel).
     */
    public function filamentColor(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Urgent => 'warning',
            self::Critical => 'danger',
        };
    }

    /**
     * Etiqueta en español para mostrar al usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::Info => 'Informativo',
            self::Urgent => 'Urgente',
            self::Critical => 'Crítico',
        };
    }

    /**
     * Peso numérico para ordenar alertas por severidad descendente.
     * Crítico primero, luego urgente, luego informativo.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Urgent => 2,
            self::Info => 1,
        };
    }
}
