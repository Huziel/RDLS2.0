<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ordenenvio')) {
            throw new RuntimeException('Required shipping table ordenenvio does not exist.');
        }

        if (! Schema::hasColumn('ordenenvio', 'assignment_mode')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->string('assignment_mode', 10)->nullable()->after('status');
            });
        }

        // FASE 6B P1 (a): backfill idempotente. Las filas legacy con delivery
        // asignado eran envios directos; el resto, pool. Nunca borra datos.
        DB::table('ordenenvio')
            ->whereNull('assignment_mode')
            ->update([
                'assignment_mode' => DB::raw("CASE WHEN delivery IS NOT NULL THEN 'direct' ELSE 'pool' END"),
            ]);

        // FASE 6B P1 (b): columna NOT NULL con default 'pool' (compatible con
        // el backfill anterior; los creadores de FASE 7 ya no podran dejar NULL).
        Schema::table('ordenenvio', function (Blueprint $table) {
            $table->string('assignment_mode', 10)->nullable(false)->default('pool')->change();
        });

        if (! Schema::hasIndex('ordenenvio', 'shipping_assignment_mode_index')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->index('assignment_mode', 'shipping_assignment_mode_index');
            });
        }

        // FASE 6B P1 (c): un pedido solo puede tener un envio. Preflight de
        // duplicados: si existen, abortar con diagnostico (jamas borrar datos).
        $duplicateShipments = DB::table('ordenenvio')
            ->select('ordenCompra')
            ->groupBy('ordenCompra')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateShipments) {
            throw new RuntimeException(
                'Duplicate ordenCompra rows in ordenenvio must be resolved before enforcing a unique index.'
            );
        }

        if (! Schema::hasIndex('ordenenvio', 'shipping_orden_compra_unique')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->unique('ordenCompra', 'shipping_orden_compra_unique');
            });
        }

        // FASE 6B P0-1 (d): indice unico sobre la evidencia de entrega
        // (tabla imageevidence, columna orderC). Preflight de duplicados.
        if (Schema::hasTable('imageevidence')) {
            $duplicateEvidence = DB::table('imageevidence')
                ->select('orderC')
                ->groupBy('orderC')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($duplicateEvidence) {
                throw new RuntimeException(
                    'Duplicate orderC rows in imageevidence must be resolved before enforcing a unique index.'
                );
            }

            if (! Schema::hasIndex('imageevidence', 'evidence_order_c_unique')) {
                Schema::table('imageevidence', function (Blueprint $table) {
                    $table->unique('orderC', 'evidence_order_c_unique');
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ordenenvio')) {
            return;
        }

        if (Schema::hasIndex('ordenenvio', 'shipping_orden_compra_unique')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->dropUnique('shipping_orden_compra_unique');
            });
        }

        if (Schema::hasIndex('ordenenvio', 'shipping_assignment_mode_index')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->dropIndex('shipping_assignment_mode_index');
            });
        }

        // FASE 6B: el rollback devuelve la columna a nullable en lugar de
        // eliminarla, para no perder los valores de assignment_mode ya
        // inferidos (backfill). Ningun dato de filas se destruye.
        if (Schema::hasColumn('ordenenvio', 'assignment_mode')) {
            Schema::table('ordenenvio', function (Blueprint $table) {
                $table->string('assignment_mode', 10)->nullable()->change();
            });
        }

        if (Schema::hasTable('imageevidence') && Schema::hasIndex('imageevidence', 'evidence_order_c_unique')) {
            Schema::table('imageevidence', function (Blueprint $table) {
                $table->dropUnique('evidence_order_c_unique');
            });
        }
    }
};
