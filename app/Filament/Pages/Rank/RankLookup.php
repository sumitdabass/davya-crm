<?php

namespace App\Filament\Pages\Rank;

use App\Filament\Resources\Rank\Concerns\RestrictsToRankRoles;
use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\Seat;
use App\Models\Rank\University;
use App\Services\Rank\BranchFamilies;
use App\Services\Rank\CollegePreferenceOrder;
use App\Services\Rank\GeminiCounsellor;
use App\Services\Rank\RankPredictor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class RankLookup extends Page implements HasForms
{
    use InteractsWithForms;
    use RestrictsToRankRoles;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Rank Lookup';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.rank.rank-lookup';

    public ?array $data = [];

    public bool $showAll = false;

    public array $notes = [];                // institute_id => string|null

    public array $notesGeneratedFor = [];    // institute_ids already attempted (so we don't re-call Gemini for cache misses)

    public function showMore(): void
    {
        $this->showAll = true;
    }

    private function generateMissingNotes(Collection $colleges, int $upTo, int $rank, string $userRegion, int $year): void
    {
        if (empty($this->data['aiOn'])) {
            return;
        }

        $counsellor = app(GeminiCounsellor::class);
        $branchFilterHash = md5(json_encode($this->data['branch_families'] ?? []));

        foreach ($colleges->take($upTo) as $col) {
            $instId = $col['branches'][0]['institute_id'] ?? null;
            if (! $instId || in_array($instId, $this->notesGeneratedFor, true)) {
                continue;
            }

            $note = $counsellor->note([
                'institute_name' => $col['institute'],
                'rank' => $rank,
                'region' => $userRegion,
                'year' => $year,
                'branch_filter_hash' => $branchFilterHash,
                'branches' => array_map(fn ($b) => [
                    'branch_name' => $b['branch'],
                    'shift' => $b['shift'],
                    'bucket' => $b['bucket'],
                    'cushion_pct' => $b['cushion_pct'],
                    'prediction_max' => $b['prediction_max'],
                    'sliding_max' => $b['rounds']['sliding']['max'] ?? null,
                    'r3_max' => $b['rounds']['3']['max'] ?? null,
                    'yoy_delta_pct' => null, // YoY computation deferred until 2025 data lands
                    'seat_count' => $b['seat_count'],
                ], $col['branches']),
            ]);

            $this->notes[$instId] = $note;
            $this->notesGeneratedFor[] = $instId;
        }
    }

    public function mount(): void
    {
        $latestYear = Cutoff::max('year') ?? (int) date('Y');
        $ipu = University::where('code', 'IPU')->first();
        $btech = $ipu ? Course::where('university_id', $ipu->id)->where('name', 'B.Tech')->first() : null;
        $jee = QualifyingExam::where('code', 'JEE_MAIN')->first();
        $counselling = AdmissionProcess::where('code', 'COUNSELLING')->first();

        $this->form->fill([
            'university_id' => $ipu?->id,
            'course_id' => $btech?->id,
            'qualifying_exam_id' => $jee?->id,
            'admission_process_id' => $counselling?->id,
            'year' => (int) $latestYear,
            'region' => 'delhi',
            'user_rank' => null,
            'show_all' => false,
            'branch_families' => [],
            'aiOn' => false,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('university_id')->label('University')
                    ->options(University::pluck('name', 'id'))->required()->reactive(),
                Select::make('course_id')->label('Course')
                    ->options(fn ($get) => Course::where('university_id', $get('university_id'))->pluck('name', 'id'))
                    ->required()->reactive(),
                Select::make('qualifying_exam_id')->label('Qualifying Exam')
                    ->options(QualifyingExam::pluck('name', 'id'))->required(),
                Select::make('admission_process_id')->label('Admission Process')
                    ->options(AdmissionProcess::pluck('name', 'id'))->required(),
                Select::make('year')->options(fn () => Cutoff::distinct()->orderBy('year', 'desc')->pluck('year', 'year')->toArray())->required(),
                Select::make('region')->options(['delhi' => 'Delhi', 'outside_delhi' => 'Outside Delhi'])->required(),
                Select::make('branch_families')
                    ->label('Preferred branch families')
                    ->multiple()
                    ->options(BranchFamilies::all())
                    ->placeholder('All branches (no filter)')
                    ->helperText('Pick one or more — selecting Computer / IT auto-includes CSE, AI, ML, IT, etc. Leave empty to see everything.')
                    ->columnSpan(2),
                TextInput::make('user_rank')->label('Your Rank')->numeric()->minValue(1)->required()->placeholder('e.g. 50000'),
                Select::make('show_all')->label('Filter')->options([
                    false => 'Only branches my rank fits',
                    true => 'All branches (highlight matching)',
                ])->default(false),
                Toggle::make('aiOn')
                    ->label('Generate AI counselling notes')
                    ->default(false)
                    ->columnSpan(2),
            ])
            ->columns(4)
            ->statePath('data');
    }

    public function getResultsProperty(): array
    {
        if (empty($this->data['user_rank']) || empty($this->data['university_id'])) {
            return [
                'rank' => null,
                'prediction_round' => null,
                'prediction_region' => null,
                'user_region' => null,
                'colleges' => collect(),
                'visible_count' => 0,
            ];
        }

        $rank = (int) $this->data['user_rank'];
        $userRegion = $this->data['region'];
        $predictionRound = $userRegion === 'delhi' ? 'sliding' : '3';
        $predictionRegion = 'delhi';
        $predictor = new RankPredictor;

        $branchIds = null;
        if (! empty($this->data['branch_families'])) {
            $branchIds = BranchFamilies::expandToBranchIds(
                $this->data['branch_families'],
                (int) $this->data['course_id'],
            );
            if (empty($branchIds)) {
                return [
                    'rank' => $rank,
                    'prediction_round' => $predictionRound,
                    'prediction_region' => $predictionRegion,
                    'user_region' => $userRegion,
                    'colleges' => collect(),
                    'visible_count' => 0,
                ];
            }
        }

        $cutoffsQuery = Cutoff::with(['institute', 'branch'])
            ->where('university_id', $this->data['university_id'])
            ->where('course_id', $this->data['course_id'])
            ->where('qualifying_exam_id', $this->data['qualifying_exam_id'])
            ->where('year', $this->data['year'])
            ->where('region', $predictionRegion);
        if ($branchIds !== null) {
            $cutoffsQuery->whereIn('branch_id', $branchIds);
        }
        $cutoffs = $cutoffsQuery->get();

        $byKey = [];
        foreach ($cutoffs as $c) {
            $key = $c->institute_id.'|'.$c->branch_id.'|'.($c->shift ?? '');
            if (! isset($byKey[$key])) {
                $byKey[$key] = [
                    'institute' => $c->institute->name,
                    'branch' => $c->branch->name,
                    'shift' => $c->shift,
                    'institute_id' => $c->institute_id,
                    'branch_id' => $c->branch_id,
                    'rounds' => ['1' => null, '2' => null, '3' => null, 'sliding' => null],
                    'seat_count' => null,
                ];
            }
            $byKey[$key]['rounds'][$c->round] = ['min' => $c->min_rank, 'max' => $c->max_rank];
        }

        // Drop rows whose prediction-round cell isn't eligible (missing, out-of-band, or cushion > 50%).
        $byKey = array_filter($byKey, function ($row) use ($rank, $predictionRound, $predictor) {
            $cell = $row['rounds'][$predictionRound] ?? null;

            return $cell && $predictor->isEligible($rank, $cell);
        });

        if (! empty($byKey)) {
            $instIds = array_unique(array_column($byKey, 'institute_id'));
            $branchIdsAll = array_unique(array_column($byKey, 'branch_id'));
            $seats = Seat::where('university_id', $this->data['university_id'])
                ->where('course_id', $this->data['course_id'])
                ->where('year', $this->data['year'])
                ->whereIn('institute_id', $instIds)
                ->whereIn('branch_id', $branchIdsAll)
                ->get()
                ->keyBy(fn ($s) => $s->institute_id.'|'.$s->branch_id);

            foreach ($byKey as $key => &$row) {
                $cell = $row['rounds'][$predictionRound];
                $seat = $seats->get($row['institute_id'].'|'.$row['branch_id']);
                $row['seat_count'] = $seat?->seat_count;
                $row['prediction_max'] = $cell['max'];
                $row['cushion_pct'] = $predictor->cushionPct($rank, $cell['max']);
                $row['bucket'] = $predictor->bucket($rank, $cell['max']);
            }
            unset($row);
        }

        // Group rows by college; sort branches inside each college by prediction_max ASC; sort colleges by demand priority.
        $colleges = collect($byKey)
            ->groupBy('institute')
            ->map(fn ($branches, $institute) => [
                'institute' => $institute,
                'priority' => CollegePreferenceOrder::sortKey($institute),
                'branches' => $branches->sortBy('prediction_max')->values()->all(),
            ])
            ->sort(fn ($a, $b) => $a['priority'] <=> $b['priority'] ?: strcasecmp($a['institute'], $b['institute']))
            ->values();

        $this->generateMissingNotes(
            $colleges,
            $this->showAll ? $colleges->count() : min(7, $colleges->count()),
            $rank,
            $userRegion,
            (int) $this->data['year'],
        );

        return [
            'rank' => $rank,
            'prediction_round' => $predictionRound,
            'prediction_region' => $predictionRegion,
            'user_region' => $userRegion,
            'colleges' => $colleges,
            'visible_count' => $this->showAll ? $colleges->count() : min(7, $colleges->count()),
            'notes' => $this->notes,
        ];
    }

    public function submit(): void
    {
        $this->form->getState();
    }
}
