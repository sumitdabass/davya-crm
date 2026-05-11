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
}
