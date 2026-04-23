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
            );

        $this->assertSame('Meeting Scheduled', $s->fresh()->stage, 'soft warning should not block save');
    }

    public function test_drag_to_closed_without_reason_blocks(): void
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

        Livewire::test(KanbanBoard::class)
            ->call('moveStudentToStage', $s->id, 'Closed')
            ->assertNotified(
                Notification::make()
                    ->title('Stage move blocked')
                    ->body('[Closed requires reason] close_reason is required.')
                    ->danger()
            );

        $this->assertSame('Lead Captured', $s->fresh()->stage, 'hard block should prevent save');
    }
}
