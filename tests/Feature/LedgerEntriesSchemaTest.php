<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LedgerEntriesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_entries_table_has_required_columns(): void
    {
        $cols = ['id','account','delta_amount','source_type','source_id','note','created_at'];
        foreach ($cols as $c) {
            $this->assertTrue(Schema::hasColumn('ledger_entries', $c), "missing $c");
        }
    }
}
