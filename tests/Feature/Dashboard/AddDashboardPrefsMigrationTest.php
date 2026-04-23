<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddDashboardPrefsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_dashboard_prefs_json_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'dashboard_prefs'));
    }

    public function test_user_model_casts_dashboard_prefs_as_array(): void
    {
        $this->seed();
        $user = User::first();
        $user->dashboard_prefs = ['dashboard' => ['enabled' => ['stuck_leads']]];
        $user->save();

        $fresh = User::find($user->id);
        $this->assertIsArray($fresh->dashboard_prefs);
        $this->assertSame(['stuck_leads'], $fresh->dashboard_prefs['dashboard']['enabled']);
    }

    public function test_dashboard_prefs_defaults_to_null(): void
    {
        $this->seed();
        $user = User::first();
        $this->assertNull($user->dashboard_prefs);
    }
}
