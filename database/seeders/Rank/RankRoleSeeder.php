<?php

namespace Database\Seeders\Rank;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RankRoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['rank.view', 'rank.manage'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $rankAdmin = Role::firstOrCreate(['name' => 'rank-admin', 'guard_name' => 'web']);
        $rankAdmin->givePermissionTo(['rank.view', 'rank.manage']);

        $admin = Role::where('name', 'admin')->first();
        if ($admin) {
            $admin->givePermissionTo(['rank.view', 'rank.manage']);
        }

        $this->command?->info('Rank roles + permissions seeded.');
    }
}
