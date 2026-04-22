<?php

namespace Tests\Feature;

use App\Models\Student;
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
}
