<?php

namespace Tests\Feature;

use App\Filament\Pages\DuplicateFlagsReview;
use App\Models\DuplicateFlag;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicateFlagsReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;
    private User $sonam;
    private User $nikhil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->sumit  = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();
        $this->nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $this->sumit->must_change_password = false;
        $this->sumit->save();
    }

    private function produceHeadConflict(string $phone = '9100090001'): array
    {
        Student::create([
            'phone' => $phone, 'name' => 'Existing Nikhil',
            'owner_id' => $this->nikhil->id, 'referrer_id' => $this->nikhil->id,
            'lead_source' => 'Sheet:Nikhil', 'stage' => 'Lead Captured',
            'preference_r1' => 'ABC',
        ]);
        $svc = new LeadIntakeService;
        $r = $svc->ingest(['phone' => $phone, 'owner_name' => 'Sonam', 'name' => 'Incoming Sonam']);
        return [$r['flag'], $r['student']];
    }

    public function test_admin_can_see_open_flag_on_page(): void
    {
        $this->produceHeadConflict();
        $this->actingAs($this->sumit);

        Livewire::test(DuplicateFlagsReview::class)
            ->assertStatus(200)
            ->assertSee('Existing Nikhil')
            ->assertSee('Incoming Sonam')
            ->assertSee('head_ownership');
    }

    public function test_non_admin_blocked(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->firstOrFail();
        $nisha->must_change_password = false; $nisha->save();
        $this->actingAs($nisha);

        $this->assertFalse(DuplicateFlagsReview::canAccess());
    }

    public function test_head_also_blocked(): void
    {
        $this->sonam->must_change_password = false; $this->sonam->save();
        $this->actingAs($this->sonam);
        $this->assertTrue($this->sonam->hasRole('head'));
        $this->assertFalse(DuplicateFlagsReview::canAccess());
    }

    public function test_resolve_keeps_chosen_and_moves_children(): void
    {
        [$flag, $newStudent] = $this->produceHeadConflict();

        // Attach note + payment to the Nikhil (studentA) side so we can verify re-parent.
        $noteId = StudentNote::create([
            'student_id' => $flag->student_a_id,
            'body' => 'nikhil pre-existing note',
            'author_id' => $this->nikhil->id,
        ])->id;
        $paymentId = Payment::create([
            'student_id' => $flag->student_a_id,
            'type' => 'advance', 'amount' => 1000, 'received_at' => now(),
            'recorded_by_user_id' => $this->nikhil->id,
        ])->id;

        $this->actingAs($this->sumit);

        Livewire::test(DuplicateFlagsReview::class)
            ->call('resolveFlag', $flag->id, $newStudent->id); // keep the Sonam one

        $flag->refresh();
        $this->assertNotNull($flag->resolved_at);
        $this->assertSame($newStudent->id, $flag->kept_student_id);
        $this->assertSame($this->sumit->id, $flag->resolved_by_user_id);

        // Loser (Nikhil row) gone
        $this->assertDatabaseMissing('students', ['id' => $flag->student_a_id]);

        // Children re-parented to the keeper
        $this->assertDatabaseHas('student_notes', ['id' => $noteId, 'student_id' => $newStudent->id]);
        $this->assertDatabaseHas('payments',      ['id' => $paymentId, 'student_id' => $newStudent->id]);

        // Keeper is no longer flagged
        $keeper = $newStudent->fresh();
        $this->assertFalse((bool) $keeper->flagged_for_review);
        $this->assertNull($keeper->flag_reason);
    }

    public function test_resolve_twice_is_safe(): void
    {
        [$flag, $newStudent] = $this->produceHeadConflict('9100090002');
        $this->actingAs($this->sumit);

        Livewire::test(DuplicateFlagsReview::class)
            ->call('resolveFlag', $flag->id, $newStudent->id);

        // Second call should warn, not error.
        Livewire::test(DuplicateFlagsReview::class)
            ->call('resolveFlag', $flag->id, $newStudent->id)
            ->assertStatus(200);
    }
}
