<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use App\Services\LeadImport\ImportAction;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadIntakeServiceParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_preview_action_matches_what_ingest_does_for_plain_insert(): void
    {
        $svc = app(LeadIntakeService::class);
        $payload = ['phone' => '9000000100', 'course' => 'BCA'];

        $previewed = $svc->preview($payload);
        $this->assertSame(ImportAction::CREATE, $previewed->action);

        // Same payload, now ingest
        $result = $svc->ingest($payload);
        $this->assertArrayHasKey('student', $result);
        $this->assertArrayNotHasKey('duplicate', $result);
        $this->assertArrayNotHasKey('flag', $result);
    }

    public function test_preview_flags_head_vs_head_conflict(): void
    {
        $svc = app(LeadIntakeService::class);
        $svc->ingest(['phone' => '9000000200', 'course' => 'BBA', 'owner_name' => 'Sonam']);

        $preview = $svc->preview(['phone' => '9000000200', 'course' => 'BBA', 'owner_name' => 'Nikhil']);
        $this->assertSame(ImportAction::FLAG, $preview->action);
        $this->assertNotNull($preview->existingStudentId);
    }

    public function test_preview_rejects_same_tier_duplicate(): void
    {
        $svc = app(LeadIntakeService::class);
        $first = $svc->ingest(['phone' => '9000000300', 'course' => 'BCA', 'owner_name' => 'Sumit']);

        $preview = $svc->preview(['phone' => '9000000300', 'course' => 'BCA', 'owner_name' => 'Sumit']);
        $this->assertSame(ImportAction::REJECT, $preview->action);
        $this->assertSame($first['student']->id, $preview->existingStudentId);
    }

    public function test_preview_merges_when_incoming_tier_beats_existing(): void
    {
        $svc = app(LeadIntakeService::class);
        $first = $svc->ingest(['phone' => '9000000400', 'course' => 'BCA', 'owner_name' => 'Sumit']);

        $preview = $svc->preview(['phone' => '9000000400', 'course' => 'BCA', 'owner_name' => 'Sonam']);
        $this->assertSame(ImportAction::MERGE, $preview->action);
        $this->assertSame($first['student']->id, $preview->existingStudentId);
    }

    public function test_preview_rejects_blank_phone(): void
    {
        $preview = app(LeadIntakeService::class)->preview(['phone' => '', 'course' => 'BCA']);
        $this->assertSame(ImportAction::REJECT, $preview->action);
        $this->assertSame('phone missing or unparseable', $preview->reason);
    }

    public function test_preview_does_not_write_to_db(): void
    {
        $before = Student::count();
        app(LeadIntakeService::class)->preview(['phone' => '9000000500', 'course' => 'BCA']);
        $this->assertSame($before, Student::count());
    }

    public function test_merge_demotion_reparents_meetings(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sonam = User::where('email', 'sonam@davya.local')->first();

        // Sumit-owned lead lands first; later Sonam re-ingests the same phone.
        // Per LeadPriority Sonam > Sumit, so Sumit's existing row is demoted.
        $sumitStudent = Student::create([
            'phone' => '9444000999',
            'name' => 'Walk-in',
            'owner_id' => $sumit->id,
            'lead_source' => 'Walk-in',
            'stage' => 'Lead Captured',
        ]);

        Meeting::create([
            'student_id' => $sumitStudent->id,
            'owner_id' => $sumit->id,
            'created_by_id' => $sumit->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
        ]);

        app(LeadIntakeService::class)->ingest([
            'phone' => '9444000999',
            'name' => 'Walk-in',
            'owner_name' => 'Sonam',
            'source' => 'Sheet:Sonam',
        ]);

        $winner = Student::where('owner_id', $sonam->id)
            ->where('phone', '9444000999')->first();
        $this->assertNotNull($winner, 'Sonam-owned winner row must exist after MERGE.');

        $this->assertSame(
            1,
            Meeting::where('student_id', $winner->id)->count(),
            'Meeting must reparent from demoted Sumit row to Sonam winner row.'
        );
        $this->assertSame(
            0,
            Meeting::where('student_id', $sumitStudent->id)->count(),
            'Demoted Sumit row must no longer own the meeting.'
        );
    }
}
