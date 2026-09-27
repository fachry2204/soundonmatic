<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $names = ['automation.view', 'automation.run', 'automation.retry', 'mappings.manage', 'sessions.manage'];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'])->syncPermissions($names);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->syncPermissions(['automation.view', 'automation.run', 'automation.retry']);
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web'])->syncPermissions(['automation.view']);
    }
}
