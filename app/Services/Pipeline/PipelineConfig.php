<?php
// app/Services/Pipeline/PipelineConfig.php
namespace App\Services\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PipelineConfig
{
    private const CACHE_KEY = 'pipeline-config:default-stages';
    private const CACHE_TTL = 3600;

    /** @return Collection<int,Stage> */
    public function stages(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Pipeline::default()
                ->stages()
                ->orderBy('display_order')
                ->get();
        });
    }

    public function openStages(): Collection  { return $this->stages()->where('stage_type', Stage::TYPE_OPEN)->values(); }
    public function wonStages(): Collection   { return $this->stages()->where('stage_type', Stage::TYPE_WON)->values(); }
    public function lostStages(): Collection  { return $this->stages()->where('stage_type', Stage::TYPE_LOST)->values(); }

    public function stageByName(string $name): ?Stage
    {
        return $this->stages()->firstWhere('name', $name);
    }

    public function stageById(int $id): ?Stage
    {
        return $this->stages()->firstWhere('id', $id);
    }

    /** @return string[] Stage names in display order — drop-in replacement for PipelineStage::values() */
    public function stageNames(): array
    {
        return $this->stages()->pluck('name')->all();
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
