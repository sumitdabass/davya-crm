<?php

namespace Tests\Feature\MobilePipeline;

use App\Models\Student;
use App\Models\User;
use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobilePipelineRenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);
        $this->actingAs($u);

        return $u;
    }

    public function test_mobile_switcher_renders_every_stage(): void
    {
        $this->admin();
        $html = $this->get('/admin/kanban')->assertOk()->getContent();

        // The mobile switcher block is present (CSS hides it >=768px, but it's in the DOM).
        $this->assertStringContainsString('pl-switcher', $html);
        foreach (app(PipelineConfig::class)->stageNames() as $name) {
            $this->assertStringContainsString($name, $html);
        }
    }
}
