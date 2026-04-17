<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FailedExtractionsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_extractions_table_has_required_columns(): void
    {
        $cols = ['id','slack_message_id','slack_channel','raw_input','error_reason','created_at'];
        foreach ($cols as $c) {
            $this->assertTrue(Schema::hasColumn('failed_extractions', $c), "missing $c");
        }
    }
}
