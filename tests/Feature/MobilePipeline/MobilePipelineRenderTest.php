<?php

namespace Tests\Feature\MobilePipeline;

use App\Filament\Pages\KanbanBoard;
use App\Models\Pipeline;
use App\Models\Student;
use App\Models\User;
use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_move_via_page_method_still_blocks_on_hard_rule(): void
    {
        $admin = $this->admin();

        // The mobile sheet's go() calls the SAME moveStudentToStage the desktop drag uses.
        // Reuse the EXACT hard-block setup from
        // KanbanSoftWarningsTest::test_drag_to_closed_without_reason_blocks_and_surfaces_missing_fields:
        // a HARD rule (Any -> Closed requires close_reason, FIELD_CHECK is_not_empty) is seeded by
        // database/migrations/..._seed_default_transition_rules.php and active after $this->seed().
        $leadCapturedId = Pipeline::default()->stages()->where('name', 'Lead Captured')->value('id');
        $s = Student::create([
            'phone' => '9999900020', 'name' => 'Test', 'owner_id' => $admin->id,
            'referrer_id' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured', 'stage_id' => $leadCapturedId,
        ]);

        // Hard block with a FIELD_CHECK failure returns missing_fields so the mobile sheet
        // can open the inline fix-up modal — no toast is sent in that path.
        $component = Livewire::test(KanbanBoard::class);
        $return = $component->instance()->moveStudentToStage($s->id, 'Closed');

        $this->assertSame('Lead Captured', $s->fresh()->stage, 'hard block should prevent save');
        $this->assertFalse($return['ok']);
        $this->assertSame(['close_reason'], $return['missing_fields']);
        $this->assertSame($s->id, $return['student_id']);
        $this->assertSame('Closed', $return['target_stage']);
    }
}
