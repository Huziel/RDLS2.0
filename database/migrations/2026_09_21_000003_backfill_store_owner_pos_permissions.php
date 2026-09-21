<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $owner = Role::findOrCreate('store-owner', 'web');
        $owner->givePermissionTo([
            Permission::findOrCreate('pos.use', 'web'),
            Permission::findOrCreate('pos.history', 'web'),
        ]);

        if (! Schema::hasTable('log') || ! Schema::hasTable('liks')) {
            return;
        }

        User::query()
            ->whereIn('name', Store::query()->select('createdby'))
            ->chunkById(100, function ($users) use ($owner) {
                foreach ($users as $user) {
                    if (! $user->hasAnyRole(['store-owner', 'super-admin'])) {
                        $user->assignRole($owner);
                    }
                }
            });
    }

    public function down(): void
    {
        // Existing permission assignments cannot be distinguished safely from later assignments.
    }
};
