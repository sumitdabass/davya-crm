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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class RankLookup extends Page implements HasForms
{
    use InteractsWithForms;
    use RestrictsToRankRoles;

    /**
     * Sumit's preferred institute display order (2026-05-01).
     * Lower number = appears first. Match by case-insensitive substring on institute name.
     * Anything not matched gets weight 1000 → falls below the listed colleges, sorted alphabetically.
     */
    private const INSTITUTE_PRIORITY = [
        'maharaja agrasen' => 10,                // MAIT
        'maharaja surajmal' => 20,               // MSIT
        'bharati vidyapeeth' => 30,              // BVP
        'bhagwan parshuram' => 40,               // BPIT
        'vivekananda institute of professional' => 50, // VIPS
        'akhilesh das gupta' => 60,              // Dr Akhilesh
        'guru teg bahadur institute' => 70,      // GTBIT
        'guru tegh bahadur 4th centenary' => 80, // GTB 4th Centenary
        'university school of information' => 90, // USICT
        'university school of automation' => 91,  // USAR
        'university school of chemical' => 92,    // USCT
    ];

    private function instituteSortKey(string $name): int
    {
        $lc = strtolower($name);
        foreach (self::INSTITUTE_PRIORITY as $needle => $weight) {
            if (str_contains($lc, $needle)) {
                return $weight;
            }
        }

        return 1000;
    }

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Rank Lookup';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.rank.rank-lookup';

    public ?array $data = [];

    public bool $showAll = false;

    public function showMore(): void
    {
        $this->showAll = true;
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
            'aiOn' => true,
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
                    ->default(true)
                    ->columnSpan(2),
            ])
            ->columns(4)
            ->statePath('data');
    }

    public function getResultsProperty(): array
    {
        if (empty($this->data['user_rank']) || empty($this->data['university_id'])) {
            return ['rows' => collect(), 'rank' => null, 'prediction_round' => null];
        }

        $rank = (int) $this->data['user_rank'];
        $userRegion = $this->data['region'];

        // Per Sumit (2026-05-01): always use **Delhi-region** cutoffs as prediction source,
        // but pick a different round depending on where the student belongs:
        //   Delhi student          → Sliding (R4) Delhi cutoffs
        //   Outside Delhi student  → R3        Delhi cutoffs
        $predictionRound = $userRegion === 'delhi' ? 'sliding' : '3';
        $predictionRegion = 'delhi';

        $cutoffs = Cutoff::with(['institute', 'branch'])
            ->where('university_id', $this->data['university_id'])
            ->where('course_id', $this->data['course_id'])
            ->where('qualifying_exam_id', $this->data['qualifying_exam_id'])
            ->where('year', $this->data['year'])
            ->where('region', $predictionRegion)
            ->get();

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

        if (! empty($byKey)) {
            $instIds = array_unique(array_column($byKey, 'institute_id'));
            $branchIds = array_unique(array_column($byKey, 'branch_id'));
            $seats = Seat::where('university_id', $this->data['university_id'])
                ->where('course_id', $this->data['course_id'])
                ->where('year', $this->data['year'])
                ->whereIn('institute_id', $instIds)
                ->whereIn('branch_id', $branchIds)
                ->get()
                ->keyBy(fn ($s) => $s->institute_id.'|'.$s->branch_id);
            foreach ($byKey as $k => &$row) {
                $seatRow = $seats->get($row['institute_id'].'|'.$row['branch_id']);
                $row['seat_count'] = $seatRow?->seat_count;
                $predCell = $row['rounds'][$predictionRound] ?? null;
                $row['fits_prediction'] = $predCell
                    && $predCell['min'] <= $rank
                    && $rank <= $predCell['max'];
                $row['pred_max'] = $predCell['max'] ?? PHP_INT_MAX;
            }
            unset($row);
        }

        $rows = collect($byKey);
        if (empty($this->data['show_all'])) {
            $rows = $rows->filter(fn ($r) => $r['fits_prediction']);
        }
        // Group by college (Sumit's preferred order: MAIT, MSIT, BVP, BPIT, VIPS, Dr Akhilesh,
        // GTBIT, GTB 4th Centenary, then USICT/USAR/USCT, then alphabetical), with branches
        // inside each college sorted low → high by the prediction round's max rank.
        $rows = $rows->sortBy([
            fn ($a, $b) => $this->instituteSortKey($a['institute']) <=> $this->instituteSortKey($b['institute']),
            fn ($a, $b) => strcasecmp($a['institute'], $b['institute']),
            fn ($a, $b) => $a['pred_max'] <=> $b['pred_max'],
        ])->values();

        return [
            'rows' => $rows,
            'rank' => $rank,
            'prediction_round' => $predictionRound,
            'prediction_region' => $predictionRegion,
            'user_region' => $userRegion,
        ];
    }

    public function submit(): void
    {
        $this->form->getState();
    }
}
