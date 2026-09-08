<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'sellers.approve', 'sellers.suspend',
            'orders.view', 'disputes.resolve', 'refunds.issue',
            'catalog.manage', 'coupons.manage',
            'finance.view', 'finance.export',
            'settings.manage',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        Role::findByName('super_admin')->givePermissionTo(Permission::all());
        Role::findByName('support_agent')->givePermissionTo(['orders.view', 'disputes.resolve', 'sellers.approve']);
        Role::findByName('finance')->givePermissionTo(['finance.view', 'finance.export', 'refunds.issue']);
        Role::findByName('catalog_manager')->givePermissionTo(['catalog.manage', 'coupons.manage']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
