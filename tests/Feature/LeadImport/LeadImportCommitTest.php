<?php

namespace Tests\Feature\LeadImport;

use App\Models\LeadImportBatch;
use App\Models\Student;
use App\Models\User;
use App\Services\LeadImport\LeadImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadImportCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    public function test_commits_new_rows_and_writes_batch_row(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $svc = app(LeadImportService::class);

        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000000800\tBCA\t1234\tD\tFees\tNisha\n"
             . "2026-04-22\t9000000801\tBBA\t5678\tOD\t\tNisha\n";
        $preview = $svc->preview('sonam', $tsv);
        $batch = $svc->commit($preview, $admin);

        $this->assertInstanceOf(LeadImportBatch::class, $batch);
        $this->assertSame('sonam', $batch->source);
        $this->assertSame(2, $batch->row_count);
        $this->assertSame(2, $batch->created_count);
        $this->assertSame(0, $batch->rejected_count);
        $this->assertNull($batch->rejections_csv_path);  // no rejections → no CSV written
        $this->assertDatabaseHas('students', ['phone' => '9000000800']);
        $this->assertDatabaseHas('students', ['phone' => '9000000801']);
    }

    public function test_rejected_rows_are_written_to_csv(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $svc = app(LeadImportService::class);

        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t\tBCA\t\t\t\t\n"
             . "2026-04-22\t9000000900\tBBA\t\t\t\t\n";
        $preview = $svc->preview('sonam', $tsv);
        $batch = $svc->commit($preview, $admin);

        $this->assertSame(1, $batch->rejected_count);
        $this->assertSame(1, $batch->created_count);
        $this->assertNotNull($batch->rejections_csv_path);
        Storage::disk('local')->assertExists($batch->rejections_csv_path);

        $csv = Storage::disk('local')->get($batch->rejections_csv_path);
        $this->assertStringContainsString('row_number,reason', $csv);
        $this->assertStringContainsString('phone missing or unparseable', $csv);
    }

    public function test_exception_during_commit_rolls_back(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $svc = app(LeadImportService::class);

        // Build a minimal valid student payload (owner_id/referrer_id/name/lead_source are NOT NULL).
        $validPayload = fn (string $phone) => [
            'phone'        => $phone,
            'name'         => 'Test Student',
            'course'       => 'BCA',
            'stage'        => 'Lead Captured',
            'owner_id'     => $admin->id,
            'referrer_id'  => $admin->id,
            'lead_source'  => 'test',
        ];

        // Action 1 would insert successfully; action 2 is a MERGE pointing to a
        // non-existent existing_student_id, which makes executeMerge() throw
        // via Student::findOrFail(). Deterministic trigger for rollback.
        $preview = new \App\Services\LeadImport\ImportPreview('sonam', [
            \App\Services\LeadImport\ImportAction::create(
                $validPayload('9000001000'),
                2,
            ),
            \App\Services\LeadImport\ImportAction::merge(
                $validPayload('9000001001'),
                existingId: 999999,
                rowNumber: 3,
            ),
        ]);

        $before = Student::count();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            $svc->commit($preview, $admin);
        } finally {
            $this->assertSame($before, Student::count(), 'rollback should restore student count');
            $this->assertSame(0, LeadImportBatch::count(), 'batch row should not persist on rollback');
        }
    }
}
