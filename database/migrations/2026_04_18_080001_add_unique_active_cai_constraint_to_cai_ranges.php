<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garantiza a nivel de base de datos que NUNCA puedan existir dos CaiRange
 * activos simultáneamente para la misma combinación (document_type, establishment_id).
 *
 * Esta es la red de seguridad fiscal más importante del módulo CAI. Si por un
 * bug en PHP, race condition o importación manual llegara a quedar
 * `is_active = true` en dos rangos de la misma sucursal y mismo tipo de
 * documento, los resolvers asignarían correlativos inconsistentes y el
 * sistema generaría facturas con números duplicados ante el SAR.
 *
 * Implementación elegida — TRIGGERS + columna regular + UNIQUE:
 *
 *   Opción A (descartada): columna GENERATED STORED con expresión CASE WHEN.
 *     → Falla en MySQL 8 con error 1215 porque `establishment_id` es FK a
 *       `establishments.id` y MySQL rechaza generated columns que mezclan
 *       columnas con FK + conversión de tipos dentro de la expresión.
 *
 *   Opción B (elegida): columna `active_lookup` regular VARCHAR nullable,
 *     UNIQUE sobre ella, y DOS TRIGGERS (BEFORE INSERT y BEFORE UPDATE) que
 *     mantienen el valor calculado en sync. Esta técnica:
 *       - Es compatible con MySQL 5.6+ sin depender del parser de generated
 *         columns.
 *       - Produce exactamente la misma semántica: NULL cuando is_active=0,
 *         "doc_estab" cuando is_active=1.
 *       - NULLs no cuentan en UNIQUE de MySQL → la constraint se aplica
 *         solo sobre filas activas, que es lo que queremos.
 *       - No se puede saltear con UPDATE raw porque los triggers corren
 *         antes de cualquier INSERT/UPDATE, venga de Eloquent o de SQL puro.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Columna regular VARCHAR(64) nullable.
        //    Los triggers la rellenarán antes de cualquier INSERT/UPDATE.
        //    hasColumn guard: en caso de que un intento anterior de esta
        //    migración haya quedado a mitad, no reintentar la creación.
        if (! Schema::hasColumn('cai_ranges', 'active_lookup')) {
            Schema::table('cai_ranges', function (Blueprint $table) {
                $table->string('active_lookup', 64)
                    ->nullable()
                    ->after('is_active');
            });
        }

        // 2. UNIQUE INDEX sobre la columna.
        //    NULLs no cuentan en UNIQUE de MySQL, por lo que la constraint
        //    se aplica solo a registros activos (donde el trigger puso valor).
        //    Guard: no crear el índice si ya existe (recuperación de intento
        //    fallido).
        $indexExists = collect(DB::select(
            "SHOW INDEX FROM cai_ranges WHERE Key_name = 'uniq_active_cai_per_doc_estab'"
        ))->isNotEmpty();

        if (! $indexExists) {
            Schema::table('cai_ranges', function (Blueprint $table) {
                $table->unique('active_lookup', 'uniq_active_cai_per_doc_estab');
            });
        }

        // 3. Limpiar triggers si algún intento anterior los dejó a medias.
        DB::unprepared('DROP TRIGGER IF EXISTS cai_ranges_sync_active_lookup_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS cai_ranges_sync_active_lookup_before_update');

        // 4. TRIGGER BEFORE INSERT: calcula active_lookup para filas nuevas.
        DB::unprepared(<<<SQL
            CREATE TRIGGER cai_ranges_sync_active_lookup_before_insert
            BEFORE INSERT ON cai_ranges
            FOR EACH ROW
            BEGIN
                IF NEW.is_active = 1 THEN
                    SET NEW.active_lookup = CONCAT(NEW.document_type, '_', COALESCE(NEW.establishment_id, 0));
                ELSE
                    SET NEW.active_lookup = NULL;
                END IF;
            END
        SQL);

        // 5. TRIGGER BEFORE UPDATE: recalcula active_lookup cuando cambia
        //    is_active, document_type o establishment_id.
        DB::unprepared(<<<SQL
            CREATE TRIGGER cai_ranges_sync_active_lookup_before_update
            BEFORE UPDATE ON cai_ranges
            FOR EACH ROW
            BEGIN
                IF NEW.is_active = 1 THEN
                    SET NEW.active_lookup = CONCAT(NEW.document_type, '_', COALESCE(NEW.establishment_id, 0));
                ELSE
                    SET NEW.active_lookup = NULL;
                END IF;
            END
        SQL);

        // 6. Backfill: para cualquier fila que ya exista en la tabla antes
        //    de esta migración (ej: el CAI activo actual de la matriz), el
        //    UPDATE "self" dispara el trigger BEFORE UPDATE y rellena
        //    active_lookup. Es idempotente — correrlo de nuevo no produce
        //    cambios.
        DB::statement('UPDATE cai_ranges SET updated_at = updated_at');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cai_ranges_sync_active_lookup_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS cai_ranges_sync_active_lookup_before_insert');

        Schema::table('cai_ranges', function (Blueprint $table) {
            $table->dropUnique('uniq_active_cai_per_doc_estab');
        });

        Schema::table('cai_ranges', function (Blueprint $table) {
            $table->dropColumn('active_lookup');
        });
    }
};
