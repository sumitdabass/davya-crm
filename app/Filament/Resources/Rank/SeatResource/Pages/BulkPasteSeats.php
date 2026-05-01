<?php

namespace App\Filament\Resources\Rank\SeatResource\Pages;

use App\Filament\Resources\Rank\SeatResource;
use App\Models\Rank\University;
use App\Services\Rank\RankBulkParser;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class BulkPasteSeats extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = SeatResource::class;

    protected static string $view = 'filament.pages.rank.bulk-paste-seats';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'year' => (int) date('Y'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('university_id')->label('University')
                    ->options(University::pluck('name', 'id'))->required()->searchable(),
                TextInput::make('year')->numeric()->required()->minValue(2000)->maxValue(2100),
                Textarea::make('paste')
                    ->label('Paste Excel data here')
                    ->required()
                    ->rows(20)
                    ->columnSpanFull()
                    ->helperText('Format: Institute<TAB>Course<TAB>Branch<TAB>Seats (one row per branch). Course is matched by name (e.g. "B.Tech") under the selected University. Header row is auto-skipped.'),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $parser = new RankBulkParser;
        $result = $parser->importSeats($data['paste'], [
            'university_id' => (int) $data['university_id'],
            'year' => (int) $data['year'],
        ]);

        $msg = "Imported {$result['imported']} seat rows · skipped {$result['skipped']}";
        if (! empty($result['errors'])) {
            $msg .= ' · '.count($result['errors']).' errors';
        }

        Notification::make()
            ->title($msg)
            ->body(! empty($result['errors']) ? implode("\n", array_slice($result['errors'], 0, 5)) : null)
            ->{empty($result['errors']) ? 'success' : 'warning'}()
            ->send();

        $this->redirect(SeatResource::getUrl('index'));
    }
}
