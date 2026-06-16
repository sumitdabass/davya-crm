<?php

namespace Database\Seeders\Rank;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RankRoleSeeder extends Seeder
{
    public function run(): void
    {
        // Legacy permissions kept for back-compat.
        $all = ['rank.view', 'rank.manage'];

        // New scoped permissions: dataset x capability.
        $scoped = [
            'rank.ipu.predict', 'rank.ipu.analyse',
            'rank.dtu.predict', 'rank.dtu.analyse',
        ];

        foreach (array_merge($all, $scoped) as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $matrix = [
            'rank-ipu-predict' => ['rank.ipu.predict'],
            'rank-ipu-analyse' => ['rank.ipu.analyse'],
            'rank-dtu-predict' => ['rank.dtu.predict'],
            'rank-dtu-analyse' => ['rank.dtu.analyse'],
        ];
        foreach ($matrix as $roleName => $perms) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])->givePermissionTo($perms);
        }

        // Back-compat: legacy rank-admin and admin/super_admin get everything.
        $superPerms = array_merge($all, $scoped);
        foreach (['rank-admin', 'admin', 'super_admin'] as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->givePermissionTo($superPerms);
        }

        $this->command?->info('Rank roles + scoped permissions seeded.');
    }
}
