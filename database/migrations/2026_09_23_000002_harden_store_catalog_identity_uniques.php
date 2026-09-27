<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['passcatalago', 'liks'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("8B-S catalog identity preflight: missing {$table}.");
            }
        }
        foreach ([['passcatalago', 'idTienda'], ['liks', 'serial'], ['liks', 'createdby']] as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                throw new RuntimeException("8B-S catalog identity preflight: missing {$table}.{$column}.");
            }
            if (DB::table($table)->whereNotNull($column)->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException("8B-S catalog identity preflight: duplicate {$table}.{$column} values.");
            }
        }

        $this->addUnique('passcatalago', 'idTienda', 'passcatalago_store_unique');
        $this->addUnique('liks', 'serial', 'stores_serial_unique');
        $this->addUnique('liks', 'createdby', 'stores_owner_unique');
    }

    public function down(): void
    {
        // Deliberately non-destructive: removing these identities would reopen
        // ambiguous tenant and capability resolution.
    }

    private function addUnique(string $table, string $column, string $name): void
    {
        if (Schema::hasIndex($table, [$column], 'unique')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $name) {
            $blueprint->unique($column, $name);
        });
    }
};
