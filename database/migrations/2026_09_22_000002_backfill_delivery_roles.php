<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * FASE 6B P1-3: los usuarios creados antes de la introduccion de roles
     * (bundle legacy) quedaron sin los roles que las rutas de routes/api.php
     * exigen (deliver / store-owner y permisos delivery.* / orders).
     * Backfill idempotente, guardado contra duplicados en model_has_roles.
     * Patron del backfill FASE 5 (2026_09_21_000001/000003).
     */
    public function up(): void
    {
        $permissions = [
            // Orders (usados por el panel del dueno; ver seeder FASE 5)
            'orders.create',
            'orders.read',
            'orders.update-status',
            // Delivery side (usados en routes/api.php)
            'delivery.manage',
            'delivery.block',
            'delivery.accept',
            'delivery.complete',
            'delivery.cancel',
            'delivery.profile',
            'delivery.wallet',
            'delivery.location',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $ownerRole = Role::findOrCreate('store-owner', 'web');
        $ownerRole->givePermissionTo([
            'orders.create', 'orders.read', 'orders.update-status',
            'delivery.manage', 'delivery.block',
        ]);

        $riderRole = Role::findOrCreate('deliver', 'web');
        $riderRole->givePermissionTo([
            'delivery.accept', 'delivery.complete', 'delivery.cancel',
            'delivery.profile', 'delivery.wallet', 'delivery.location',
        ]);

        // FASE 6B P2: el guard cubre tambien las tablas del modulo delivery
        // (datospersonales / anexosdeliver) para no romper esquemas legacy sin
        // ese modulo (log/liks existen pero las de delivery no).
        if (! Schema::hasTable('log') || ! Schema::hasTable('liks')
            || ! Schema::hasTable('datospersonales') || ! Schema::hasTable('anexosdeliver')) {
            return;
        }

        // store-owner -> duenos reales: usuarios que son createdby de una
        // tienda (tabla liks). Evita duplicados y no toca super-admins.
        User::query()
            ->whereIn('name', Store::query()->select('createdby'))
            ->chunkById(100, function ($users) use ($ownerRole) {
                foreach ($users as $user) {
                    if (! $user->hasAnyRole(['store-owner', 'super-admin'])) {
                        $user->assignRole($ownerRole);
                    }
                }
            });

        // deliver -> regla exacta confirmada en la re-auditoria (FASE 6B P2):
        // active + type '3' + perfil verificado (datospersonales.verificado='1')
        // => rol deliver. El vinculo a tiendas (anexosdeliver) NO es requisito
        // del rol: un rider verificado sin vinculo debe poder obtener el rol
        // para luego usar attach-store. La elegibilidad por tienda (verificado
        // + rol + DeliveryLink sin bloqueo) se valida en
        // DeliveryController::eligibleDeliverer al emitir/aceptar, no aqui.
        User::query()
            ->where('active', true)
            ->where('type', '3')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('datospersonales')
                    ->whereColumn('datospersonales.idLog', 'log.id')
                    ->where('verificado', '1');
            })
            ->whereDoesntHave('roles')
            ->chunkById(100, function ($users) use ($riderRole) {
                foreach ($users as $user) {
                    $user->assignRole($riderRole);
                }
            });
    }

    public function down(): void
    {
        // Las asignaciones de roles creadas aqui no pueden distinguirse con
        // seguridad de asignaciones posteriores. No se revierten (mismo
        // criterio que los backfills de FASE 5).
    }
};
