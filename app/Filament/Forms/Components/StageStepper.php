<?php

namespace App\Filament\Forms\Components;

use App\Services\Pipeline\PipelineConfig;
use Filament\Forms\Components\Field;

class StageStepper extends Field
{
    protected string $view = 'filament.forms.components.stage-stepper';

    /**
     * Ordered pipeline stages for the stepper view.
     *
     * @return array<int, array{name: string, type: string}>
     */
    public function getStages(): array
    {
        return app(PipelineConfig::class)->stages()
            ->map(fn ($s) => ['name' => $s->name, 'type' => $s->stage_type])
            ->values()
            ->all();
    }
}
