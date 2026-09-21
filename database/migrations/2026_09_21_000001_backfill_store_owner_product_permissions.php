<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect([
            'products.create',
            'products.read',
            'products.update',
            'products.delete',
        ])->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        $owner = Role::findOrCreate('store-owner', 'web');
        $owner->givePermissionTo($permissions);

        if (! Schema::hasTable('log')) {
            return;
        }

        User::query()
            ->where('type', '1')
            ->whereDoesntHave('roles')
            ->chunkById(100, function ($users) use ($owner) {
                foreach ($users as $user) {
                    $user->assignRole($owner);
                }
            });
    }

    public function down(): void
    {
        // Existing role assignments cannot be distinguished safely from later assignments.
    }
};
