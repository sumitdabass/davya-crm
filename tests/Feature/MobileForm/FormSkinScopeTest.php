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
}
