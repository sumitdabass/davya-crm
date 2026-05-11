<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;

class IncomePage extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}/income';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.book.income';

    /** @var \App\Models\Book\Company */
    public $companyModel;

    /** @var \App\Models\Book\FiscalYear */
    public $fyModel;

    public function mount(string $company, string $fy): void
    {
        abort_unless(config('books.enabled'), 404);
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $this->companyModel = Company::where('slug', $company)->firstOrFail();
        $this->fyModel = FiscalYear::where('company_id', $this->companyModel->id)
            ->where('label', $fy)
            ->firstOrFail();
    }

    public function getIncome()
    {
        return IncomeEntry::where('fiscal_year_id', $this->fyModel->id)
            ->orderByDesc('occurred_on')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createIncome')
                ->label('+ Add Income')
                ->form([
                    DatePicker::make('occurred_on')->required(),
                    TextInput::make('source')->required(),
                    TextInput::make('amount')->numeric()->required(),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (array $data) => IncomeEntry::create(array_merge($data, [
                    'company_id' => $this->companyModel->id,
                    'fiscal_year_id' => $this->fyModel->id,
                ]))),
        ];
    }

    public function editIncomeAction(): Action
    {
        return Action::make('editIncome')
            ->label('Edit Income')
            ->fillForm(function (array $arguments): array {
                $entry = IncomeEntry::findOrFail($arguments['id']);

                return [
                    'occurred_on' => $entry->occurred_on?->toDateString(),
                    'source' => $entry->source,
                    'amount' => (float) $entry->amount,
                    'notes' => $entry->notes,
                ];
            })
            ->form([
                DatePicker::make('occurred_on')->required(),
                TextInput::make('source')->required(),
                TextInput::make('amount')->numeric()->required(),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                IncomeEntry::findOrFail($arguments['id'])->update([
                    'occurred_on' => $data['occurred_on'],
                    'source' => $data['source'],
                    'amount' => $data['amount'],
                    'notes' => $data['notes'] ?? null,
                ]);
            });
    }

    public function deleteIncomeAction(): Action
    {
        return Action::make('deleteIncome')
            ->label('Delete Income')
            ->requiresConfirmation()
            ->modalHeading('Delete income entry')
            ->modalDescription(function (array $arguments): string {
                $entry = IncomeEntry::find($arguments['id']);

                return 'Delete income from "'.($entry?->source ?? 'this source').'"? This cannot be undone.';
            })
            ->color('danger')
            ->action(function (array $arguments): void {
                if ($this->fyModel->is_closed) {
                    throw new \DomainException('FY is closed');
                }
                IncomeEntry::findOrFail($arguments['id'])->delete();
            });
    }
}
