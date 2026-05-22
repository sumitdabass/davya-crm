<?php

namespace Tests\Feature;

use App\Filament\Pages\SettingsLanding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsLandingTilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_tiles_no_longer_include_reports_items(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->firstOrFail();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin);

        $labels = array_map(fn ($t) => $t['label'], (new SettingsLanding())->getTiles());

        $this->assertContains('Fields', $labels);
        $this->assertContains('Stages', $labels);
        $this->assertContains('Users & roles', $labels);
        $this->assertContains('Lead import', $labels);

        // Moved to /admin/reports landing (the Reports nav-group home).
        $this->assertNotContains('Duplicate review', $labels);
        $this->assertNotContains('Activity audit', $labels);
    }
}
