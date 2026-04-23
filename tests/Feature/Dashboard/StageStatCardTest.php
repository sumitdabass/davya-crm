<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\StageStatCard;
use App\Dashboard\CardRegistry;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageStatCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        CardRegistry::reset();
    }

    public function test_card_id_uses_stage_id(): void
    {
        $stage = Stage::first();
        $card = new StageStatCard($stage);
        $this->assertSame('stage.'.$stage->id, $card->id());
    }

    public function test_label_matches_stage_name(): void
    {
        $stage = Stage::first();
        $card = new StageStatCard($stage);
        $this->assertSame($stage->name, $card->label());
    }

    public function test_count_scopes_to_students_in_that_stage(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $stage = Stage::first();

        Student::create([
            'phone' => '9333000001', 'name' => 'L1', 'owner_id' => $admin->id,
            'lead_source' => 'Website', 'stage' => $stage->name,
            'stage_id' => $stage->id,
        ]);
        Student::create([
            'phone' => '9333000002', 'name' => 'L2', 'owner_id' => $admin->id,
            'lead_source' => 'Website', 'stage' => $stage->name,
            'stage_id' => $stage->id,
        ]);

        $card = new StageStatCard($stage);
        $this->assertSame(2, $card->drillDown($admin)->query->count());
    }

    public function test_registry_generates_one_card_per_stage(): void
    {
        $stageCount = Stage::count();
        $ids = array_map(fn ($c) => $c->id(), CardRegistry::all());
        $stageCardIds = array_filter($ids, fn ($id) => str_starts_with($id, 'stage.'));
        $this->assertCount($stageCount, $stageCardIds);
    }

    public function test_registry_picks_up_newly_created_stage(): void
    {
        $before = count(CardRegistry::all());

        // Use whatever extra columns Stage model requires — inspect first.
        // Minimum fields typically: name, pipeline_id, display_order, stage_type.
        $pipeline = \App\Models\Pipeline::where('is_default', true)->first()
            ?? \App\Models\Pipeline::first();

        Stage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Brand New Stage',
            'stage_type' => 'OPEN',
            'display_order' => 999,
        ]);
        CardRegistry::reset();

        $after = count(CardRegistry::all());
        $this->assertSame($before + 1, $after);
    }
}
