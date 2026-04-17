<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvestmentsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_investments_table_has_required_columns(): void
    {
        $cols = ['id','asset_name','amount','direction','transacted_at',
                 'slack_message_id','raw_input','created_at','updated_at'];
        foreach ($cols as $c) {
            $this->assertTrue(Schema::hasColumn('investments', $c), "missing $c");
        }
    }
}
