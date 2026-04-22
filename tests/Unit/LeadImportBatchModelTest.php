<?php

namespace Tests\Unit;

use App\Models\LeadImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImportBatchModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_batch_row(): void
    {
        $this->seed();
        $user = User::role('admin')->firstOrFail();
        $batch = LeadImportBatch::create([
            'user_id' => $user->id,
            'source' => 'sonam',
            'row_count' => 40,
            'created_count' => 28,
            'merged_count' => 8,
            'flagged_count' => 3,
            'rejected_count' => 1,
            'rejections_csv_path' => 'lead-imports/uuid.csv',
        ]);
        $this->assertTrue($batch->exists);
        $this->assertSame(40, $batch->row_count);
        $this->assertSame($user->id, $batch->user->id);
    }
}
