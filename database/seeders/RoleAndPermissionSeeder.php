<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Products
            'products.create',
            'products.read',
            'products.update',
            'products.delete',
            // Orders
            'orders.create',
            'orders.read',
            'orders.update-status',
            // POS
            'pos.use',
            'pos.history',
            // Coupons
            'coupons.manage',
            // Delivery management (store owner side)
            'delivery.manage',
            'delivery.block',
            // Appointments
            'appointments.manage',
            // Barters / Trueques
            'barters.manage',
            // Invoicing
            'invoicing.use',
            // Store settings
            'store.settings',
            'store.themes',
            'store.qr',
            // Delivery (repartidor side)
            'delivery.accept',
            'delivery.complete',
            'delivery.cancel',
            'delivery.profile',
            'delivery.wallet',
            'delivery.location',
            // Customer
            'cart.use',
            'orders.history',
            // Users
            'users.manage',
            // Dashboard
            'dashboard.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Store Owner role
        $owner = Role::firstOrCreate(['name' => 'store-owner', 'guard_name' => 'web']);
        $owner->syncPermissions([
            'products.create', 'products.read', 'products.update', 'products.delete',
            'orders.create', 'orders.read', 'orders.update-status',
            'pos.use', 'pos.history',
            'coupons.manage',
            'delivery.manage', 'delivery.block',
            'appointments.manage',
            'barters.manage',
            'invoicing.use',
            'store.settings', 'store.themes', 'store.qr',
            'dashboard.view',
        ]);

        // Deliver role
        $deliver = Role::firstOrCreate(['name' => 'deliver', 'guard_name' => 'web']);
        $deliver->syncPermissions([
            'delivery.accept', 'delivery.complete', 'delivery.cancel',
            'delivery.profile', 'delivery.wallet', 'delivery.location',
        ]);

        // Customer role
        $customer = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $customer->syncPermissions([
            'cart.use',
            'orders.history',
        ]);
    }
}
