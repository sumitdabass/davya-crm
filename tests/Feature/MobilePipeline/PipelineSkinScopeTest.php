<?php

namespace Tests\Feature\MobilePipeline;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineSkinScopeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);

        return $u;
    }

    public function test_skin_loads_on_kanban_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/kanban')
            ->assertOk()
            ->assertSee('pipeline-skin.css', false)
            ->assertSee('davya-pipeline-skin', false);
    }

    public function test_skin_absent_on_students_list(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/students')
            ->assertOk()
            ->assertDontSee('pipeline-skin.css', false);
    }
}
