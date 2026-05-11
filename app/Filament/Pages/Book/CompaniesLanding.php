<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;

class CompaniesLanding extends Page
{
    protected static ?string $slug = 'books';
    protected static ?string $title = 'Books';
    protected static ?string $navigationGroup = 'Books';
    protected static string $view = 'filament.pages.book.companies-landing';

    /**
     * Filament calls `abort_unless(static::canAccess(), 403)` during mount.
     * We need a 404 (not 403) when the feature flag is off so the module
     * is fully hidden. Returning `true` here when the flag is off would
     * leak the route; instead we keep canAccess strict and override
     * `mountCanAuthorizeAccess` to swap the abort code when the flag is off.
     */
    public function mountCanAuthorizeAccess(): void
    {
        // Flag off must yield 404 so the module is fully hidden.
        // Flag on but missing super_admin yields 403 (the Filament default code).
        if (! (bool) config('books.enabled')) {
            abort(404);
        }

        abort_unless((bool) auth()->user()?->isSuperAdmin(), 403);
    }

    public static function canAccess(): bool
    {
        return (bool) config('books.enabled')
            && (bool) auth()->user()?->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getCompanies()
    {
        return Company::orderBy('name')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCompany')
                ->label('+ New Company')
                ->form([
                    TextInput::make('name')->required(),
                    TextInput::make('slug')
                        ->required()
                        ->unique('book_companies', 'slug')
                        ->alphaDash(),
                    Select::make('currency')
                        ->options(['INR' => 'INR'])
                        ->default('INR')
                        ->required(),
                ])
                ->action(fn (array $data) => Company::create($data)),

            Action::make('viewHistory')
                ->label('History')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn () => url('/admin/books/history')),
        ];
    }

    public function editCompanyAction(): Action
    {
        return Action::make('editCompany')
            ->modalHeading('Edit company')
            ->fillForm(function (array $arguments): array {
                $c = Company::findOrFail($arguments['id']);
                return [
                    'name' => $c->name,
                    'slug' => $c->slug,
                ];
            })
            ->form([
                TextInput::make('name')->required(),
                TextInput::make('slug')
                    ->required()
                    ->alphaDash()
                    ->helperText('URL slug — change with care, it rewrites bookmarks.'),
            ])
            ->action(function (array $data, array $arguments): void {
                $c = Company::findOrFail($arguments['id']);
                // Unique check that excludes self
                if (Company::where('slug', $data['slug'])->where('id', '!=', $c->id)->exists()) {
                    throw new \DomainException("Slug '{$data['slug']}' is already taken.");
                }
                $c->update(['name' => $data['name'], 'slug' => $data['slug']]);
            });
    }

    public function createFirstFyAction(): Action
    {
        return Action::make('createFirstFy')
            ->modalHeading('Create first fiscal year')
            ->fillForm(function (array $arguments): array {
                $year = (int) now()->year;
                $month = (int) now()->month;
                // If we're past April, the current FY started this April; else previous April.
                $fyStartYear = $month >= 4 ? $year : $year - 1;

                return [
                    'label'      => $fyStartYear.'-'.substr((string) ($fyStartYear + 1), -2),
                    'start_date' => $fyStartYear.'-04-01',
                    'end_date'   => ($fyStartYear + 1).'-03-31',
                ];
            })
            ->form([
                TextInput::make('label')
                    ->required()
                    ->placeholder('e.g. 2025-26')
                    ->helperText('Indian financial year label (Apr–Mar).'),
                \Filament\Forms\Components\DatePicker::make('start_date')
                    ->label('Start (Apr 1)')
                    ->required(),
                \Filament\Forms\Components\DatePicker::make('end_date')
                    ->label('End (Mar 31)')
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $fy = \App\Models\Book\FiscalYear::create([
                    'company_id' => $arguments['company_id'],
                    'label'      => $data['label'],
                    'start_date' => $data['start_date'],
                    'end_date'   => $data['end_date'],
                ]);
                $this->redirect(url('/admin/books/'.$arguments['company_slug'].'/'.$fy->label));
            });
    }
}
