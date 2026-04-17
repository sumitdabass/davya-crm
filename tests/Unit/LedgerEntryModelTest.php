<?php
namespace Tests\Unit;

use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerEntryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_entry_casts_delta_as_decimal_and_accepts_negative(): void
    {
        $e = LedgerEntry::create([
            'account' => 'davya',
            'delta_amount' => -5000,
            'source_type' => 'expense',
            'source_id' => 1,
            'note' => 'expense: Marketing',
        ]);
        $this->assertSame('-5000.00', (string) $e->fresh()->delta_amount);
        $this->assertSame('davya', $e->fresh()->account);
    }
}
