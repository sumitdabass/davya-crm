<?php

namespace Tests\Feature\Rank;

use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RankRolesSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeds_four_scoped_roles_and_permissions(): void
    {
        $this->seed(RankRoleSeeder::class);

        foreach (['rank.ipu.predict', 'rank.ipu.analyse', 'rank.dtu.predict', 'rank.dtu.analyse'] as $perm) {
            $this->assertNotNull(Permission::where('name', $perm)->first(), "missing $perm");
        }
        foreach (['rank-ipu-predict', 'rank-ipu-analyse', 'rank-dtu-predict', 'rank-dtu-analyse'] as $role) {
            $this->assertNotNull(Role::where('name', $role)->first(), "missing $role");
        }

        $this->assertTrue(
            Role::where('name', 'rank-dtu-analyse')->first()->hasPermissionTo('rank.dtu.analyse')
        );
    }
}
