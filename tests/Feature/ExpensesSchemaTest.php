<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpensesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_table_has_required_columns(): void
    {
        $cols = ['id','amount','category','description','paid_at',
                 'slack_message_id','raw_input','created_at','updated_at'];
        foreach ($cols as $c) {
            $this->assertTrue(Schema::hasColumn('expenses', $c), "missing $c");
        }
    }
}
