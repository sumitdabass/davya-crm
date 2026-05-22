<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'head', 'member', 'freelancer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Grant `use ai-agent` to admin AND super_admin (super_admin role is created by its own
        // migration in 2026_05_02_000300_create_super_admin_role, so it may exist independently).
        if (Permission::where('name', 'use ai-agent')->exists()) {
            foreach (['admin', 'super_admin'] as $name) {
                $role = Role::where('name', $name)->first();
                $role?->givePermissionTo('use ai-agent');
            }
        }
    }
}
