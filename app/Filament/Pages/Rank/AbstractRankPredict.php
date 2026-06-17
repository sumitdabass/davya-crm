<?php

namespace App\Filament\Pages\Rank;

use App\Models\Rank\Course;
use App\Models\Rank\University;
use App\Rank\RankDataset;
use App\Services\Rank\DatasetCutoffPredictor;
use App\Services\Rank\PredictorContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

abstract class AbstractRankPredict extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static string $view = 'filament.pages.rank.rank-predict';

    public ?array $data = [];

    /** Dataset token: 'ipu' | 'dtu'. */
    abstract protected function datasetToken(): string;

    protected function showsCourseSelector(): bool
    {
        return ! RankDataset::courseFixedToBtech($this->datasetToken());
    }

    protected function showsCategorySelectors(): bool
    {
        return RankDataset::hasCategoryDimension($this->datasetToken());
    }

    public function mount(): void
    {
        $this->form->fill([
            'gender' => 'male',
            'category' => $this->showsCategorySelectors() ? 'general' : null,
            'sub_category' => $this->showsCategorySelectors() ? 'gender_neutral' : null,
            'region' => 'delhi',
            'user_rank' => null,
            'course_id' => $this->defaultCourseId(),
            'within_reach_only' => true,
        ]);
    }

    private function universityIds(): array
    {
        return University::whereIn('code', RankDataset::universityCodes($this->datasetToken()))->pluck('id')->all();
    }

    private function defaultCourseId(): ?int
    {
        $q = Course::whereIn('university_id', $this->universityIds());
        if (RankDataset::courseFixedToBtech($this->datasetToken())) {
            return $q->where('name', 'B.Tech')->value('id');
        }

        return $q->orderBy('name')->value('id');
    }

    /** Sub-category options, filtered by gender. */
    public static function subCategoryOptions(?string $gender): array
    {
        $all = [
            'gender_neutral' => 'Gender-Neutral',
            'girl' => 'Girl',
            'single_girl' => 'Single-Girl',
            'pwd' => 'PwD',
            'defense_cw' => 'Defense (CW)',
        ];
        if (mb_strtolower((string) $gender) === 'male') {
            unset($all['girl'], $all['single_girl']);
        }

        return $all;
    }

    public function form(Form $form): Form
    {
        $schema = [
            TextInput::make('user_rank')->label('JEE-Main Rank (CRL)')->numeric()->minValue(1)
                ->required()->placeholder('e.g. 45000'),
            Select::make('gender')->options(['male' => 'Male', 'female' => 'Female'])
                ->required()->reactive()
                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                    if ($state === 'male' && in_array($get('sub_category'), ['girl', 'single_girl'], true)) {
                        $set('sub_category', 'gender_neutral');
                    }
                }),
        ];

        // Category / sub_category only apply to datasets whose cutoffs carry that
        // breakdown (DTU/JAC). IPU's cutoffs have none, so the selectors would be inert.
        if ($this->showsCategorySelectors()) {
            $schema[] = Select::make('category')->options([
                'general' => 'General', 'ews' => 'EWS', 'obc' => 'OBC', 'sc' => 'SC', 'st' => 'ST',
            ])->required();
            $schema[] = Select::make('sub_category')
                ->options(fn (callable $get) => static::subCategoryOptions($get('gender')))
                ->required();
        }

        $schema[] = Select::make('region')->options(['delhi' => 'Delhi', 'outside_delhi' => 'Outside Delhi'])->required();

        if ($this->showsCourseSelector()) {
            $schema[] = Select::make('course_id')->label('Course')
                ->options(Course::whereIn('university_id', $this->universityIds())->pluck('name', 'id'))
                ->required();
        }

        $schema[] = Toggle::make('within_reach_only')->label('Show only options within reach')->default(true);

        return $form->schema($schema)->columns(['default' => 1, 'md' => 3])->statePath('data');
    }

    public function getResultsProperty(): array
    {
        if (empty($this->data['user_rank'])) {
            return ['rows' => [], 'reach_count' => 0, 'submitted' => false];
        }

        $ctx = new PredictorContext(
            datasetToken: $this->datasetToken(),
            rank: (int) $this->data['user_rank'],
            region: $this->data['region'] ?? 'delhi',
            category: $this->data['category'] ?? 'general',
            subCategory: $this->data['sub_category'] ?? null,
            gender: $this->data['gender'] ?? null,
            courseId: isset($this->data['course_id']) ? (int) $this->data['course_id'] : null,
        );

        $result = app(DatasetCutoffPredictor::class)->predict($ctx);
        $rows = $result['rows'];
        if (! empty($this->data['within_reach_only'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['chance'] !== 'UNLIKELY'));
        }

        return ['rows' => $rows, 'reach_count' => $result['reach_count'], 'submitted' => true];
    }

    public function submit(): void
    {
        $this->form->getState();
    }
}
