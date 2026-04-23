<?php
// tests/Feature/Pipeline/StageRepositoryTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Services\Pipeline\StageRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StageRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_adds_stage_with_next_display_order(): void
    {
        $repo = app(StageRepository::class);
        $s = $repo->create(Pipeline::default(), 'Custom Stage', Stage::TYPE_OPEN);
        $this->assertSame('Custom Stage', $s->name);
        $this->assertGreaterThan(0, $s->display_order);
    }

    public function test_create_enforces_20_cap(): void
    {
        $p = Pipeline::default();
        $repo = app(StageRepository::class);
        // Seeded: 13. Add 7 more = 20.
        for ($i = 1; $i <= 7; $i++) $repo->create($p, "Extra $i", Stage::TYPE_OPEN);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/20 stages/i');
        $repo->create($p, 'OneTooMany', Stage::TYPE_OPEN);
    }

    public function test_create_rejects_duplicate_name(): void
    {
        $p = Pipeline::default();
        $repo = app(StageRepository::class);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/already exists/i');
        $repo->create($p, 'Sliding', Stage::TYPE_OPEN);
    }

    public function test_rename_updates_name_and_invalidates_cache(): void
    {
        $repo = app(StageRepository::class);
        $s = $repo->create(Pipeline::default(), 'To Rename', Stage::TYPE_OPEN);
        $renamed = $repo->rename($s, 'Renamed');
        $this->assertSame('Renamed', $renamed->fresh()->name);
    }

    public function test_reorder_renumbers_display_order(): void
    {
        $repo = app(StageRepository::class);
        $p = Pipeline::default();
        $ids = $p->stages->pluck('id')->reverse()->values()->map(fn ($id) => (int) $id)->all();
        $repo->reorder($p, $ids);
        $firstAfter = $p->stages()->orderBy('display_order')->first();
        $this->assertSame($ids[0], (int) $firstAfter->id);

        // Pin the full permutation, not just the head.
        $reordered = $p->stages()->orderBy('display_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame($ids, $reordered);
    }

    public function test_reorder_rejects_partial_id_list(): void
    {
        $repo = app(StageRepository::class);
        $p = Pipeline::default();
        $partial = $p->stages->pluck('id')->take(5)->all();  // only 5 of 13
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/current stage IDs/i');
        $repo->reorder($p, $partial);
    }

    public function test_delete_without_students_succeeds(): void
    {
        $repo = app(StageRepository::class);
        $s = $repo->create(Pipeline::default(), 'Empty', Stage::TYPE_OPEN);
        $repo->delete($s);
        $this->assertDatabaseMissing('stages', ['id' => $s->id]);
    }

    public function test_delete_with_students_and_no_target_throws(): void
    {
        $repo = app(StageRepository::class);
        $p = Pipeline::default();
        $s = $p->stages()->where('name','Meeting Scheduled')->firstOrFail();
        $ownerId = \App\Models\User::factory()->create()->id;
        \DB::table('students')->insert([
            'name'=>'S','phone'=>'9111111111','owner_id'=>$ownerId,'referrer_id'=>$ownerId,
            'lead_source'=>'t','stage'=>'Meeting Scheduled','stage_id'=>$s->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/has 1 student|transfer_to_stage_id/i');
        $repo->delete($s);
    }

    public function test_delete_with_students_and_target_migrates_then_deletes(): void
    {
        $repo = app(StageRepository::class);
        $p = Pipeline::default();
        $from = $p->stages()->where('name','Meeting Scheduled')->firstOrFail();
        $to   = $p->stages()->where('name','Meeting Done')->firstOrFail();
        $ownerId = \App\Models\User::factory()->create()->id;
        \DB::table('students')->insert([
            'name'=>'S','phone'=>'9222222222','owner_id'=>$ownerId,'referrer_id'=>$ownerId,
            'lead_source'=>'t','stage'=>'Meeting Scheduled','stage_id'=>$from->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $repo->delete($from, $to->id);
        $this->assertDatabaseMissing('stages', ['id' => $from->id]);
        $this->assertSame($to->id, \DB::table('students')->where('phone','9222222222')->value('stage_id'));
        $this->assertSame('Meeting Done', \DB::table('students')->where('phone','9222222222')->value('stage'));
    }

    public function test_change_type_moves_stage_to_new_section(): void
    {
        $repo = app(StageRepository::class);
        $p = Pipeline::default();
        $s = $p->stages()->where('name', 'Seat Allotted')->firstOrFail();
        $repo->changeType($s, Stage::TYPE_WON);
        $this->assertSame(Stage::TYPE_WON, $s->fresh()->stage_type);
    }

    public function test_delete_rejects_target_from_different_pipeline(): void
    {
        $repo = app(StageRepository::class);
        $p1 = Pipeline::default();

        // Create a second pipeline with its own stage.
        $p2 = Pipeline::create(['name' => 'Other Pipeline', 'is_default' => false]);
        $otherStage = Stage::create([
            'pipeline_id' => $p2->id, 'name' => 'Other Stage', 'stage_type' => 'OPEN', 'display_order' => 1,
        ]);

        // Try to delete a student-holding stage from p1, with transfer target in p2.
        $from = $p1->stages()->where('name', 'Meeting Scheduled')->firstOrFail();
        $ownerId = \App\Models\User::factory()->create()->id;
        \DB::table('students')->insert([
            'name' => 'CrossTest', 'phone' => '9333333333', 'owner_id' => $ownerId, 'referrer_id' => $ownerId,
            'lead_source' => 't', 'stage' => 'Meeting Scheduled', 'stage_id' => $from->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not found in this pipeline/i');
        $repo->delete($from, $otherStage->id);
    }
}
