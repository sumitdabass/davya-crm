<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_roles_exist_after_seeding(): void
    {
        $this->seed(\Database\Seeders\RolesSeeder::class);
        foreach (['admin', 'head', 'member', 'freelancer'] as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role]);
        }
    }

    public function test_sumit_has_admin_and_head_roles_after_seeding(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->assertNotNull($sumit);
        $this->assertTrue($sumit->hasRole('admin'));
        $this->assertTrue($sumit->hasRole('head'));
    }

    public function test_nisha_head_is_nikhil(): void
    {
        $this->seed();
        $nisha = User::where('name', 'Nisha')->first();
        $nikhil = User::where('name', 'Nikhil')->first();
        $this->assertNotNull($nisha);
        $this->assertNotNull($nikhil);
        $this->assertEquals($nikhil->id, $nisha->team_head_id);
    }

    public function test_kapil_is_freelancer_under_sumit_with_no_sub_team(): void
    {
        $this->seed();
        $kapil = User::where('name', 'Kapil')->first();
        $sumit = User::where('name', 'Sumit')->first();
        $this->assertNotNull($kapil);
        $this->assertTrue($kapil->is_freelancer);
        $this->assertEquals($sumit->id, $kapil->team_head_id);
        $this->assertTrue($kapil->hasRole('freelancer'));
        $this->assertSame(0, User::where('team_head_id', $kapil->id)->count(), 'freelancer must have no sub-team');
    }
}
