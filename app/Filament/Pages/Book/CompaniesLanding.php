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
        ];
    }
}
