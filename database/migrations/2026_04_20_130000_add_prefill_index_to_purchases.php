<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice compuesto para el pre-fill de documentos SAR.
 *
 * La query de SupplierDocumentPrefill filtra por supplier_id + status +
 * document_type y ordena por date DESC. El índice existente
 * (supplier_id, status) cubría el filtro pero el ORDER BY date requería
 * filesort sobre el subset.
 *
 * Con (supplier_id, status, date) el motor puede resolver filtro + orden
 * en una sola pasada al índice — crítico cuando crezca el histórico de
 * compras por proveedor.
 *
 * El índice viejo (supplier_id, status) se mantiene porque otras queries
 * lo usan (scope confirmadas por proveedor); el costo de mantener ambos
 * es marginal y evita regresiones en otras pantallas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->index(
                ['supplier_id', 'status', 'date'],
                'purchases_supplier_status_date_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_supplier_status_date_index');
        });
    }
};
