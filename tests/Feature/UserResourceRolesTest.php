<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserResourceRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(RankRoleSeeder::class);
    }

    private function actingAsAdmin(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $sumit->must_change_password = false;
        $sumit->save();
        $this->actingAs($sumit);
    }

    /** @test */
    public function creating_a_user_with_a_role_assigns_it_without_error(): void
    {
        $this->actingAsAdmin();

        // The roles Select is bound via ->relationship('roles','name'), so option
        // VALUES are role ids (what the real form submits), not role names.
        $roleId = Role::where('name', 'rank-dtu-analyse')->value('id');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Scoped Analyst',
                'email' => 'scoped.analyst@davya.local',
                'password' => 'secret-pw-123',
                'roles' => [$roleId],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'scoped.analyst@davya.local')->firstOrFail();
        $this->assertTrue($created->hasRole('rank-dtu-analyse'));
    }

    /**
     * Root-cause guard: the roles Select must NOT override its relationship
     * options with name=>name pairs. That override made the UI submit role
     * NAMES, which the BelongsToMany sync then tried to insert into the integer
     * model_has_roles.role_id column → 500 on every user create that set a role.
     *
     * @test
     */
    public function roles_select_does_not_override_options_with_names(): void
    {
        $src = file_get_contents(app_path('Filament/Resources/UserResource.php'));
        $this->assertStringNotContainsString("Role::pluck('name', 'name')", $src);
    }
}
