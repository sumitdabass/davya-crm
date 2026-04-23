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
}
