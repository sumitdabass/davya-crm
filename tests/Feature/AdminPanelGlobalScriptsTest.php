<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelGlobalScriptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_head_includes_single_global_sortablejs(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $admin->must_change_password = false;
        $admin->save();

        $html = $this->actingAs($admin)->get('/admin')->getContent();

        // Exactly one Sortable.min.js include in the rendered head — protects against
        // the per-page loaders re-creeping back in (SP#3 follow-up d).
        $count = substr_count($html, 'Sortable.min.js');
        $this->assertSame(1, $count, "Expected exactly 1 Sortable.min.js include, found {$count}");
    }
}
