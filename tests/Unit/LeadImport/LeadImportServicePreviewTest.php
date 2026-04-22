<?php

namespace Tests\Unit\LeadImport;

use App\Services\LeadImport\ImportAction;
use App\Services\LeadImport\LeadImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LeadImportServicePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_previews_tsv_paste_for_sonam_source(): void
    {
        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000000600\tBCA\t1234\tD\tFees\tNisha\n"
             . "2026-04-22\t9000000601\tBBA\t5678\tOD\t\tNisha\n";

        $preview = app(LeadImportService::class)->preview('sonam', $tsv);

        $this->assertSame('sonam', $preview->source);
        $this->assertSame(2, $preview->rowCount());
        $this->assertSame(2, $preview->countBy(ImportAction::CREATE));
    }

    public function test_bad_phone_row_previews_as_reject(): void
    {
        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t\tBCA\t\t\t\t\n";
        $preview = app(LeadImportService::class)->preview('sonam', $tsv);
        $this->assertSame(1, $preview->countBy(ImportAction::REJECT));
    }

    public function test_invalid_source_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LeadImportService::class)->preview('not-a-source', "anything");
    }

    public function test_previews_csv_upload_for_canonical_source(): void
    {
        $csv = "phone,name,course,rank,state,referrer_name,remarks,source\n"
             . "9000000700,Asha,BCA,1234,Delhi,,,\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $preview = app(LeadImportService::class)->preview('canonical', $file);
        $this->assertSame(1, $preview->rowCount());
        $this->assertSame(ImportAction::CREATE, $preview->actions[0]->action);
    }

    public function test_parse_failure_bubbles_up(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing required column/');
        app(LeadImportService::class)->preview('sonam', "A\tB\n1\t2\n");
    }
}
