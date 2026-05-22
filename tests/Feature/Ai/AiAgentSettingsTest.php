<?php
namespace Tests\Feature\Ai;

use App\Filament\Pages\AiAgentSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiAgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_toggle_permission_on_role(): void
    {
        $sa = User::where('email','sumit@davya.local')->firstOrFail();
        $sa->assignRole('super_admin');

        Livewire::actingAs($sa)
            ->test(AiAgentSettings::class)
            ->set('rolesWithAgent', ['admin', 'head'])
            ->call('save');

        $this->assertTrue(Role::findByName('head')->hasPermissionTo('use ai-agent'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('use ai-agent'));
        $this->assertFalse(Role::findByName('freelancer')->hasPermissionTo('use ai-agent'));
    }

    public function test_non_super_admin_cannot_access(): void
    {
        $u = User::factory()->create();
        $u->assignRole('admin');
        auth()->login($u);
        $this->assertFalse(AiAgentSettings::canAccess());
    }
}
