<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots inmutables de las declaraciones ISV mensuales presentadas al SAR
 * (Formulario 201 SIISAR).
 *
 * Qué NO es este modelo:
 *   - NO es una máquina de estado. Esa la dueña es `fiscal_periods` (open →
 *     declared → reopened → re-declared). Duplicarla aquí violaría SRP y
 *     crearía dos fuentes de verdad sobre el mismo concepto.
 *
 * Qué SÍ es:
 *   - Registro histórico de los totales congelados en el momento de declarar.
 *     Cada fila es una foto exacta de lo que se copió al portal SIISAR.
 *   - 1:N con `fiscal_periods`: un período puede tener múltiples snapshots —
 *     el original + cada rectificativa. Solo uno está "activo" a la vez.
 *
 * Garantía de unicidad del snapshot activo (Opción B de ISV.3b):
 *   Una columna VIRTUAL `is_active` se evalúa como `TRUE` cuando
 *   `superseded_at IS NULL`, y como `NULL` en cualquier otro caso. Un UNIQUE
 *   compuesto `(fiscal_period_id, is_active)` bloquea dos snapshots activos
 *   del mismo período (MySQL trata dos `TRUE` como iguales) pero permite
 *   múltiples supersedidos (MySQL trata dos `NULL` como distintos en UNIQUE).
 *
 *   Esto es defense-in-depth: el `IsvMonthlyDeclarationService` tiene la regla
 *   de negocio con `lockForUpdate`, pero si alguien inserta directo por tinker,
 *   seeder o migración mal diseñada, la DB rechaza el duplicado activo.
 *
 * Inmutabilidad post-creación:
 *   Las columnas fiscales (totales, fiscal_period_id, declared_at) son
 *   inmutables una vez insertadas. Esta regla la hace cumplir
 *   `IsvMonthlyDeclarationObserver` a nivel aplicación; la DB no la impone
 *   porque MySQL no tiene CHECK constraints portables sobre UPDATE.
 *
 *   Columnas MUTABLES intencionales: notes, superseded_at, superseded_by_user_id
 *   (+ updated_by auditoría). Eso permite el flujo rectificativa: al crear el
 *   snapshot nuevo, el Service marca el anterior como supersedido.
 *
 * NO tiene soft-deletes:
 *   Los registros fiscales son permanentes. El concepto de "borrado" en este
 *   dominio es `superseded_at != null`. Cualquier otro delete es un error
 *   conceptual — el Observer bloquea `deleting` explícitamente.
 *
 * Referencias SAR: Formulario 201 Secciones A, B, C, D, E; Acuerdo 189-2014
 * para rectificativas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isv_monthly_declarations', function (Blueprint $table) {
            $table->id();

            // ─── Período al que corresponde la declaración ───────────────
            $table->foreignId('fiscal_period_id')
                ->constrained()
                ->restrictOnDelete()  // nunca borrar un período con snapshots
                ->comment('Período fiscal declarado — ver fiscal_periods');

            // ─── Metadatos de la presentación al SAR ──────────────────────
            // Redundantes con FiscalPeriod.declared_at, pero el snapshot debe
            // ser autocontenido: FiscalPeriod puede reabrirse y su declared_at
            // muta con cada rectificativa; este campo es el timestamp inmutable
            // del momento en que ESTE snapshot específico fue generado.
            $table->timestamp('declared_at')
                ->comment('Momento en que este snapshot fue congelado y presentado');
            $table->foreignId('declared_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete()
                ->comment('Contador que presentó esta versión de la declaración');

            // Número de acuse/presentación que SIISAR devuelve (opcional —
            // algunos meses no lo registramos si se presenta por ventanilla).
            $table->string('siisar_acuse_number', 100)->nullable()
                ->comment('Número de acuse devuelto por SIISAR al presentar, si aplica');

            // ─── Sección A — Ventas ──────────────────────────────────────
            // Totales del Libro de Ventas del período.
            $table->decimal('ventas_gravadas', 14, 2)->default(0)
                ->comment('Ventas gravadas con ISV (Sección A1)');
            $table->decimal('ventas_exentas', 14, 2)->default(0)
                ->comment('Ventas exentas — canasta básica, exportaciones (Sección A2)');
            $table->decimal('ventas_totales', 14, 2)->default(0)
                ->comment('Suma A1 + A2 (congelada — no se recalcula)');

            // ─── Sección B — Compras ──────────────────────────────────────
            // Totales del Libro de Compras del período.
            $table->decimal('compras_gravadas', 14, 2)->default(0)
                ->comment('Compras gravadas con ISV (Sección B1)');
            $table->decimal('compras_exentas', 14, 2)->default(0)
                ->comment('Compras exentas (Sección B2)');
            $table->decimal('compras_totales', 14, 2)->default(0)
                ->comment('Suma B1 + B2 (congelada)');

            // ─── Cálculo del ISV a pagar ─────────────────────────────────
            $table->decimal('isv_debito_fiscal', 14, 2)->default(0)
                ->comment('ISV cobrado en ventas gravadas — 15% de ventas_gravadas');
            $table->decimal('isv_credito_fiscal', 14, 2)->default(0)
                ->comment('ISV pagado en compras gravadas — crédito del período');

            // Retenciones del período (Sección D). Total de las
            // isv_retentions_received del mes.
            $table->decimal('isv_retenciones_recibidas', 14, 2)->default(0)
                ->comment('Suma de retenciones ISV recibidas en el mes (Sección D)');

            // Saldo a favor del período anterior (Sección E arrastrada).
            // Nullable = 0 si es el primer período con tracking.
            $table->decimal('saldo_a_favor_anterior', 14, 2)->default(0)
                ->comment('Saldo a favor arrastrado del mes previo (Sección E)');

            // Resultado neto — uno de los dos será 0 (mutuamente excluyentes).
            $table->decimal('isv_a_pagar', 14, 2)->default(0)
                ->comment('ISV neto a pagar al SAR (0 si hay saldo a favor)');
            $table->decimal('saldo_a_favor_siguiente', 14, 2)->default(0)
                ->comment('Saldo a favor que se arrastra al mes siguiente');

            // ─── Observaciones del contador (MUTABLE) ─────────────────────
            // Único campo editable post-creación. Útil para anotar detalles
            // que aparezcan después (ej: número de acuse si llegó tarde).
            $table->text('notes')->nullable()
                ->comment('Observaciones libres del contador — campo mutable');

            // ─── Ciclo de reemplazo (rectificativa) ───────────────────────
            // Se setean cuando una rectificativa reemplaza a este snapshot.
            // El Observer permite updates solo sobre estos campos + notes.
            $table->timestamp('superseded_at')->nullable()
                ->comment('Momento en que este snapshot fue reemplazado por una rectificativa');
            $table->foreignId('superseded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete()
                ->comment('Usuario que activó la rectificativa');

            // ─── Columna VIRTUAL para UNIQUE del snapshot activo ──────────
            // Evalúa TRUE (= 1) cuando está activo, NULL cuando está supersedido.
            // Usar NULL (no FALSE) en el caso supersedido es intencional: MySQL
            // trata múltiples NULLs como distintos en UNIQUE, permitiendo que
            // un período tenga N snapshots supersedidos + 1 activo. Dos activos
            // en el mismo período colisionan en el UNIQUE compuesto y fallan.
            $table->boolean('is_active')
                ->virtualAs('CASE WHEN superseded_at IS NULL THEN 1 ELSE NULL END')
                ->nullable()
                ->comment('VIRTUAL: 1 si es el snapshot vigente, NULL si fue reemplazado');

            // ─── Auditoría estándar del proyecto ─────────────────────────
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            // NO softDeletes: los snapshots son permanentes, el "borrado lógico"
            // del dominio es superseded_at.

            // ─── Índices ─────────────────────────────────────────────────
            // UNIQUE del snapshot activo por período — garantía de DB.
            $table->unique(
                ['fiscal_period_id', 'is_active'],
                'isv_monthly_declarations_active_unique'
            );

            // Listado ordenado por fecha de declaración (cronológico). Cubre
            // el widget "declaraciones recientes" y el histórico en Filament.
            $table->index('declared_at', 'isv_monthly_declarations_declared_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isv_monthly_declarations');
    }
};
