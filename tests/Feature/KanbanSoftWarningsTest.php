<?php

namespace Tests\Feature;

use App\Filament\Pages\KanbanBoard;
use App\Models\Pipeline;
use App\Models\Student;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KanbanSoftWarningsTest extends TestCase
{
    use RefreshDatabase;

    public function test_drag_to_meeting_scheduled_without_meeting_saves_and_warns(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);

        $leadCapturedId = Pipeline::default()->stages()->where('name', 'Lead Captured')->value('id');
        $s = Student::create([
            'phone' => '9999900010', 'name' => 'Test', 'owner_id' => $sumit->id,
            'referrer_id' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured', 'stage_id' => $leadCapturedId,
        ]);

        $this->actingAs($sumit);

        Livewire::test(KanbanBoard::class)
            ->call('moveStudentToStage', $s->id, 'Meeting Scheduled')
            ->assertNotified(
                Notification::make()
                    ->title('Moved to Meeting Scheduled — some fields still missing')
                    ->body('[Meeting Scheduled needs a future meeting] record needs at least 1 meetings.')
                    ->warning()
                    ->persistent()
            );

        $this->assertSame('Meeting Scheduled', $s->fresh()->stage, 'soft warning should not block save');
    }

    public function test_drag_to_closed_without_reason_blocks_and_surfaces_missing_fields(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);

        $leadCapturedId = Pipeline::default()->stages()->where('name', 'Lead Captured')->value('id');
        $s = Student::create([
            'phone' => '9999900011', 'name' => 'Test', 'owner_id' => $sumit->id,
            'referrer_id' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured', 'stage_id' => $leadCapturedId,
        ]);

        $this->actingAs($sumit);

        // Hard block with a FIELD_CHECK failure returns missing_fields so the frontend
        // can open the inline fix-up modal — no toast is sent in that path.
        $component = Livewire::test(KanbanBoard::class);
        $return = $component->instance()->moveStudentToStage($s->id, 'Closed');

        $this->assertSame('Lead Captured', $s->fresh()->stage, 'hard block should prevent save');
        $this->assertFalse($return['ok']);
        $this->assertSame(['close_reason'], $return['missing_fields']);
        $this->assertSame($s->id, $return['student_id']);
        $this->assertSame('Closed', $return['target_stage']);
    }

    public function test_fix_and_move_sets_field_then_transitions(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);

        $leadCapturedId = Pipeline::default()->stages()->where('name', 'Lead Captured')->value('id');
        $s = Student::create([
            'phone' => '9999900012', 'name' => 'Test', 'owner_id' => $sumit->id,
            'referrer_id' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured', 'stage_id' => $leadCapturedId,
        ]);

        $this->actingAs($sumit);

        Livewire::test(KanbanBoard::class)
            ->call('fixAndMove', $s->id, 'Closed', ['close_reason' => 'Not Interested'])
            ->assertHasNoErrors();

        $fresh = $s->fresh();
        $this->assertSame('Closed', $fresh->stage, 'student should have moved after fix');
        $this->assertSame('Not Interested', $fresh->close_reason);
    }

    public function test_fix_and_move_rejects_close_reason_outside_allowed_options(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);

        $leadCapturedId = Pipeline::default()->stages()->where('name', 'Lead Captured')->value('id');
        $s = Student::create([
            'phone' => '9999900013', 'name' => 'Test', 'owner_id' => $sumit->id,
            'referrer_id' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured', 'stage_id' => $leadCapturedId,
        ]);

        $this->actingAs($sumit);

        // Free text used to reach an enum column and 500 with "Data truncated".
        $return = Livewire::test(KanbanBoard::class)->instance()
            ->fixAndMove($s->id, 'Closed', ['close_reason' => 'complete payment received']);

        $this->assertFalse($return['ok']);
        $this->assertSame(['close_reason'], $return['missing_fields']);
        $this->assertNotEmpty($return['errors']);

        $fresh = $s->fresh();
        $this->assertSame('Lead Captured', $fresh->stage);
        $this->assertNull($fresh->close_reason);
    }

    public function test_fix_field_options_offer_dropdowns_for_fixed_value_fields(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);
        $this->actingAs($sumit);

        $options = Livewire::test(KanbanBoard::class)->instance()->fixFieldOptions();

        $this->assertArrayHasKey('Completed', $options['close_reason']);
        $this->assertArrayHasKey('Ready', $options['student_response']);
        $this->assertArrayHasKey('Delhi', $options['category']);
        $this->assertArrayHasKey('plan', $options);
        $this->assertSame(['1' => 'Yes', '0' => 'No'], $options['is_ipu_registered']);
        $this->assertArrayNotHasKey('father_name', $options);
    }
}
