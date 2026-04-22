<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\ActivityDescriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityDescriberTest extends TestCase
{
    use RefreshDatabase;

    private function student(): Student
    {
        $this->seed();
        $owner = User::first();
        return Student::create([
            'phone' => '9999920000', 'name' => 'T', 'owner_id' => $owner->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
    }

    public function test_stage_changed(): void
    {
        $s = $this->student();
        $actor = User::first();
        $this->actingAs($actor);

        Activity::query()->delete();
        (new ActivityDescriber)->stageChanged($s, 'Lead Captured', 'Meeting Scheduled');

        $a = Activity::latest('id')->first();
        $this->assertSame('Moved from Lead Captured → Meeting Scheduled', $a->description);
        $this->assertSame('stage_changed', $a->event);
        $this->assertSame($actor->id, $a->causer_id);
    }

    public function test_payment_added(): void
    {
        $s = $this->student();
        Activity::query()->delete();

        $p = Payment::create([
            'student_id' => $s->id,
            'amount' => 10000,
            'received_at' => now(),
            'type' => 'advance',
            'recorded_by_user_id' => User::first()->id,
        ]);
        (new ActivityDescriber)->paymentAdded($p);

        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Added payment ₹10,000', $a->description);
        $this->assertStringContainsString('advance', $a->description);
    }

    public function test_round_entered(): void
    {
        $s = $this->student();
        Activity::query()->delete();

        $r = RoundHistory::create([
            'student_id' => $s->id, 'round_name' => 'Online_R1', 'outcome' => 'Not Allotted',
        ]);
        (new ActivityDescriber)->roundEntered($r);

        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Round entered: Round 1', $a->description);
    }

    public function test_note_added_truncates_long_body(): void
    {
        $s = $this->student();
        Activity::query()->delete();

        $n = StudentNote::create([
            'student_id' => $s->id,
            'author_id' => User::first()->id,
            'body' => 'Parent called and said they want the third round option now please',
        ]);
        (new ActivityDescriber)->noteAdded($n);

        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Added note:', $a->description);
        $this->assertLessThanOrEqual(120, strlen($a->description), 'note body should be truncated to ~60 chars + wrapper');
    }

    public function test_owner_changed(): void
    {
        $s = $this->student();
        Activity::query()->delete();

        $from = User::where('email', 'sonam@davya.local')->first();
        $to = User::where('email', 'nikhil@davya.local')->first();
        (new ActivityDescriber)->ownerChanged($s, $from, $to);

        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Reassigned owner Sonam → Nikhil', $a->description);
    }
}
