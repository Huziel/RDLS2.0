<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            throw new RuntimeException('FASE 8A permissions require the Spatie permission tables.');
        }

        $names = [
            'orders.read',
            'orders.payments.verify',
            'orders.refunds.verify',
            'orders.returns.verify',
            'orders.payments.audit',
            'orders.dispatch',
            'payments.mercado-pago.manage',
        ];
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('store-owner', 'web')->givePermissionTo($names);
        Role::findOrCreate('super-admin', 'web')->givePermissionTo([
            'orders.read',
            'orders.payments.audit',
        ]);
    }

    public function down(): void
    {
        // Assignments may have become operational authorization. Fail closed:
        // never revoke or delete permissions automatically on rollback.
    }
};
