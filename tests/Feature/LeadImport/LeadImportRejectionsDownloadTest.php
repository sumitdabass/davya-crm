<?php

namespace Tests\Feature\LeadImport;

use App\Models\LeadImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LeadImportRejectionsDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    public function test_admin_can_download_rejections_then_file_is_cleared(): void
    {
        $admin = User::role('admin')->firstOrFail();
        Storage::disk('local')->put('lead-imports/test.csv', "row_number,reason\n2,bad phone\n");

        $batch = LeadImportBatch::create([
            'user_id' => $admin->id,
            'source' => 'sonam', 'row_count' => 1, 'created_count' => 0,
            'merged_count' => 0, 'flagged_count' => 0, 'rejected_count' => 1,
            'rejections_csv_path' => 'lead-imports/test.csv',
        ]);

        $url = URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id]);

        $res = $this->actingAs($admin)->get($url);
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('bad phone', $res->streamedContent());

        $batch->refresh();
        $this->assertNull($batch->rejections_csv_path);
        Storage::disk('local')->assertMissing('lead-imports/test.csv');
    }

    public function test_non_admin_gets_403(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $member = User::whereHas('roles', fn ($q) => $q->where('name', 'member'))->firstOrFail();
        $batch = LeadImportBatch::create([
            'user_id' => $admin->id,
            'source' => 'sonam', 'row_count' => 0, 'created_count' => 0,
            'merged_count' => 0, 'flagged_count' => 0, 'rejected_count' => 0,
        ]);
        $url = URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id]);
        $this->actingAs($member)->get($url)->assertForbidden();
    }

    public function test_expired_or_missing_file_returns_410(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $batch = LeadImportBatch::create([
            'user_id' => $admin->id,
            'source' => 'sonam', 'row_count' => 0, 'created_count' => 0,
            'merged_count' => 0, 'flagged_count' => 0, 'rejected_count' => 0,
            'rejections_csv_path' => null,
        ]);
        $url = URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id]);
        $this->actingAs($admin)->get($url)->assertStatus(410);
    }
}
