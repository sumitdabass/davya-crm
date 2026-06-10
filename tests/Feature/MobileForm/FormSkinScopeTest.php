<?php

namespace Tests\Feature\MobileForm;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FormSkinScopeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);

        return $u;
    }

    public function test_skin_css_loads_on_create_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/students/create')
            ->assertOk()
            ->assertSee('student-form-skin.css', false)
            ->assertSee('davya-student-form-skin', false);
    }

    public function test_skin_css_absent_on_students_list(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/students')
            ->assertOk()
            ->assertDontSee('student-form-skin.css', false);
    }

    /**
     * Contract guard for the chip selected-state CSS. The skin styles the selected
     * ToggleButton via `input:checked + label.fi-btn`, which only works because
     * Filament renders each option as a hidden `.peer` <input> immediately followed
     * by a `.fi-btn` <label>. If a Filament upgrade changes that DOM, this fails and
     * tells us the skin's chip selector needs updating (regression that shipped
     * 2026-06-10: `label:has(input:checked)` matched nothing — the input is a sibling).
     */
    public function test_toggle_buttons_render_peer_input_plus_label_for_skin_selector(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/students/create')
            ->assertOk()
            ->assertSee('fi-fo-toggle-buttons', false)
            ->assertSee('peer pointer-events-none absolute opacity-0', false);
    }
}
