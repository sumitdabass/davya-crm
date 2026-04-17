<?php
namespace Tests\Unit;

use App\Models\FailedExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailedExtractionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_failed_extractions_can_share_slack_message_id(): void
    {
        FailedExtraction::create([
            'slack_message_id' => 'C1.1.1',
            'slack_channel' => '#student-entries',
            'raw_input' => 'gobbledy',
            'error_reason' => 'gemini invalid JSON',
        ]);
        FailedExtraction::create([
            'slack_message_id' => 'C1.1.1',
            'slack_channel' => '#student-entries',
            'raw_input' => 'gobbledy',
            'error_reason' => 'second retry: still invalid',
        ]);
        $this->assertSame(2, FailedExtraction::count());
    }
}
