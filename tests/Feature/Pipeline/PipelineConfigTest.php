<?php
// tests/Feature/Pipeline/PipelineConfigTest.php
namespace Tests\Feature\Pipeline;

use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PipelineConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_stages_returns_13_seeded_stages_in_order(): void
    {
        $stages = app(PipelineConfig::class)->stages();
        $this->assertCount(13, $stages);
        $this->assertSame('Lead Captured', $stages->first()->name);
        $this->assertSame('Closed', $stages->last()->name);
    }

    public function test_open_won_lost_buckets(): void
    {
        $cfg = app(PipelineConfig::class);
        $this->assertCount(11, $cfg->openStages());
        $this->assertCount(1,  $cfg->wonStages());
        $this->assertCount(1,  $cfg->lostStages());
    }

    public function test_stage_by_name_and_id(): void
    {
        $cfg = app(PipelineConfig::class);
        $sliding = $cfg->stageByName('Sliding');
        $this->assertNotNull($sliding);
        $this->assertSame($sliding->id, $cfg->stageById($sliding->id)->id);
        $this->assertNull($cfg->stageByName('Nonexistent'));
    }

    public function test_invalidate_clears_cache(): void
    {
        $cfg = app(PipelineConfig::class);
        $cfg->stages(); // populate cache
        // Insert a new stage directly, bypassing the service
        \DB::table('stages')->insert([
            'pipeline_id' => \App\Models\Pipeline::default()->id,
            'name' => 'Bypass', 'stage_type' => 'OPEN', 'display_order' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Cache still shows 13
        $this->assertCount(13, $cfg->stages());
        $cfg->invalidate();
        $this->assertCount(14, $cfg->stages());
    }
}
