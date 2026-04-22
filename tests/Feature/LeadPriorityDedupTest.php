<?php

namespace Tests\Feature;

use App\Models\DuplicateFlag;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadPriorityDedupTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;
    private User $sonam;
    private User $nikhil;
    private LeadIntakeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->sumit  = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();
        $this->nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $this->svc = new LeadIntakeService;
    }

    private function seedStudent(User $owner, string $phone, string $stage = 'Lead Captured'): Student
    {
        return Student::create([
            'phone'         => $phone,
            'name'          => $owner->name.' lead',
            'owner_id'      => $owner->id,
            'referrer_id'   => $owner->id,
            'lead_source'   => 'Sheet:'.$owner->name,
            'stage'         => $stage,
            'preference_r1' => 'ABC College',
        ]);
    }

    public function test_sumit_already_in_db_then_sonam_arrives_sumit_is_demoted(): void
    {
        $sumitRow = $this->seedStudent($this->sumit, '9100000100');
        StudentNote::create(['student_id' => $sumitRow->id, 'body' => 'original sumit note', 'author_id' => $this->sumit->id]);

        $r = $this->svc->ingest([
            'phone'      => '9100000100',
            'owner_name' => 'Sonam',
            'name'       => 'Priya',
        ]);

        $this->assertArrayHasKey('student', $r);
        $this->assertArrayHasKey('demoted_existing_id', $r);
        $this->assertSame($sumitRow->id, $r['demoted_existing_id']);
        $this->assertDatabaseMissing('students', ['id' => $sumitRow->id]);

        $new = $r['student'];
        $this->assertSame($this->sonam->id, $new->owner_id);
        $this->assertSame('Sheet:Sonam', $new->lead_source);

        // Note must have been re-parented, not lost.
        $this->assertDatabaseHas('student_notes', [
            'student_id' => $new->id,
            'body'       => 'original sumit note',
        ]);
    }

    public function test_sumit_already_in_db_then_nikhil_arrives_sumit_is_demoted(): void
    {
        $sumitRow = $this->seedStudent($this->sumit, '9100000101');

        $r = $this->svc->ingest([
            'phone'      => '9100000101',
            'owner_name' => 'Nikhil',
        ]);

        $this->assertArrayHasKey('demoted_existing_id', $r);
        $this->assertDatabaseMissing('students', ['id' => $sumitRow->id]);
        $this->assertSame($this->nikhil->id, $r['student']->owner_id);
    }

    public function test_sonam_already_in_db_then_sumit_arrives_sumit_is_rejected(): void
    {
        $sonamRow = $this->seedStudent($this->sonam, '9100000102');

        $r = $this->svc->ingest([
            'phone'      => '9100000102',
            'owner_name' => 'Sumit',
        ]);

        $this->assertTrue($r['duplicate'] ?? false);
        $this->assertSame($sonamRow->id, $r['existing_id']);
        $this->assertDatabaseHas('students', ['id' => $sonamRow->id, 'owner_id' => $this->sonam->id]);
    }

    public function test_nikhil_already_in_db_then_sonam_arrives_flags_both_for_review(): void
    {
        $nikhilRow = $this->seedStudent($this->nikhil, '9100000103');

        $r = $this->svc->ingest([
            'phone'      => '9100000103',
            'owner_name' => 'Sonam',
        ]);

        $this->assertArrayHasKey('flag', $r);
        $this->assertInstanceOf(DuplicateFlag::class, $r['flag']);

        $new = $r['student']->fresh();
        $old = $nikhilRow->fresh();

        $this->assertTrue((bool) $new->flagged_for_review);
        $this->assertTrue((bool) $old->flagged_for_review);
        $this->assertSame(DuplicateFlag::REASON_HEAD_OWNERSHIP, $new->flag_reason);
        $this->assertSame(DuplicateFlag::REASON_HEAD_OWNERSHIP, $old->flag_reason);

        $this->assertDatabaseHas('duplicate_flags', [
            'phone'        => '9100000103',
            'student_a_id' => $nikhilRow->id,
            'student_b_id' => $new->id,
            'reason'       => DuplicateFlag::REASON_HEAD_OWNERSHIP,
        ]);
    }

    public function test_sonam_already_in_db_then_nikhil_arrives_flags_both_for_review(): void
    {
        $sonamRow = $this->seedStudent($this->sonam, '9100000104');

        $r = $this->svc->ingest([
            'phone'      => '9100000104',
            'owner_name' => 'Nikhil',
        ]);

        $this->assertArrayHasKey('flag', $r);
        $this->assertDatabaseHas('duplicate_flags', [
            'phone'        => '9100000104',
            'student_a_id' => $sonamRow->id,
            'student_b_id' => $r['student']->id,
        ]);
    }

    public function test_same_owner_dup_is_plain_duplicate_reject(): void
    {
        $first = $this->seedStudent($this->sonam, '9100000105');

        $r = $this->svc->ingest([
            'phone'      => '9100000105',
            'owner_name' => 'Sonam',
        ]);

        $this->assertTrue($r['duplicate'] ?? false);
        $this->assertSame($first->id, $r['existing_id']);
        $this->assertDatabaseCount('duplicate_flags', 0);
    }

    public function test_demotion_re_parents_payments_and_notes(): void
    {
        $sumitRow = $this->seedStudent($this->sumit, '9100000106');
        $paymentId = Payment::create([
            'student_id'          => $sumitRow->id,
            'type'                => 'advance',
            'amount'              => 1000,
            'received_at'         => now(),
            'recorded_by_user_id' => $this->sumit->id,
        ])->id;
        $noteId = StudentNote::create([
            'student_id' => $sumitRow->id,
            'body'       => 'pre-existing note',
            'author_id'  => $this->sumit->id,
        ])->id;

        $r = $this->svc->ingest([
            'phone'      => '9100000106',
            'owner_name' => 'Sonam',
        ]);

        $newId = $r['student']->id;
        $this->assertDatabaseHas('payments',      ['id' => $paymentId, 'student_id' => $newId]);
        $this->assertDatabaseHas('student_notes', ['id' => $noteId,    'student_id' => $newId]);
        $this->assertDatabaseMissing('students',  ['id' => $sumitRow->id]);
    }
}
