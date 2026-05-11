<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;

class SectionPage extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}/section/{section}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.book.section';

    /** @var \App\Models\Book\Company */
    public $companyModel;

    /** @var \App\Models\Book\FiscalYear */
    public $fyModel;

    /** @var \App\Models\Book\Section */
    public $sectionModel;

    public function mount(string $company, string $fy, string $section): void
    {
        abort_unless(config('books.enabled'), 404);
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $this->companyModel = Company::where('slug', $company)->firstOrFail();
        $this->fyModel = FiscalYear::where('company_id', $this->companyModel->id)
            ->where('label', $fy)
            ->firstOrFail();
        $this->sectionModel = Section::where('company_id', $this->companyModel->id)
            ->where('slug', $section)
            ->firstOrFail();
    }

    public function getEntries()
    {
        return Entry::where('section_id', $this->sectionModel->id)
            ->where('fiscal_year_id', $this->fyModel->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function getVisibleMoneyColumns(): array
    {
        return $this->sectionModel->visible_money_columns;
    }

    protected function getHeaderActions(): array
    {
        $cols = $this->getVisibleMoneyColumns();
        $form = [TextInput::make('title')->required()];

        if (in_array('salary', $cols, true)) {
            $form[] = TextInput::make('salary_amount')->numeric()->default(0);
        }
        if (in_array('loan', $cols, true)) {
            $form[] = TextInput::make('loan_amount')->numeric()->default(0);
        }
        $form[] = Textarea::make('notes')->rows(2);

        return [
            Action::make('createEntry')
                ->label('+ Add Row')
                ->form($form)
                ->action(function (array $data) {
                    if ($this->fyModel->is_closed) {
                        throw new \DomainException('FY is closed');
                    }
                    Entry::create([
                        'company_id' => $this->companyModel->id,
                        'fiscal_year_id' => $this->fyModel->id,
                        'section_id' => $this->sectionModel->id,
                        'title' => $data['title'],
                        'salary_amount' => $data['salary_amount'] ?? 0,
                        'loan_amount' => $data['loan_amount'] ?? 0,
                        'notes' => $data['notes'] ?? null,
                    ]);
                }),
        ];
    }
}
