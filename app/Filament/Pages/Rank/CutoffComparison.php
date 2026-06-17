<?php

namespace App\Filament\Pages\Rank;

use App\Services\Rank\CutoffComparator;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class CutoffComparison extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Rank Analytics';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'DTU — Cutoff Trends';

    protected static ?string $title = 'DTU/NSUT/IGDTUW — Year-on-Year Cutoff Trends';

    protected static ?string $slug = 'rank/dtu/compare';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.rank.cutoff-comparison';

    /** This page compares the JAC dataset (DTU + NSUT + IGDTUW). */
    private const DATASET = 'dtu';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canRankPredict(self::DATASET);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill([
            'region' => 'delhi',
            'category' => 'general',
            'sub_category' => 'gender_neutral',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('region')->options(['delhi' => 'Delhi', 'outside_delhi' => 'Outside Delhi'])->required()->live(),
            Select::make('category')->options([
                'general' => 'General', 'ews' => 'EWS', 'obc' => 'OBC', 'sc' => 'SC', 'st' => 'ST',
            ])->required()->live(),
            Select::make('sub_category')->options([
                'gender_neutral' => 'Gender-Neutral', 'girl' => 'Girl', 'single_girl' => 'Single-Girl',
                'pwd' => 'PwD', 'defense_cw' => 'Defense (CW)',
            ])->required()->live(),
        ])->columns(['default' => 1, 'md' => 3])->statePath('data');
    }

    public function getResultsProperty(): array
    {
        return app(CutoffComparator::class)->compare(
            self::DATASET,
            $this->data['region'] ?? 'delhi',
            $this->data['category'] ?? 'general',
            $this->data['sub_category'] ?? 'gender_neutral',
        );
    }
}
