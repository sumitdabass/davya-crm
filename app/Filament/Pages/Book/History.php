<?php

namespace App\Filament\Pages\Book;

use Filament\Pages\Page;
use Spatie\Activitylog\Models\Activity;

class History extends Page
{
    protected static ?string $slug = 'books/history';

    protected static ?string $title = 'Books — Activity History';

    protected static ?string $navigationGroup = 'Books';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.book.history';

    public ?string $subjectTypeFilter = null;

    public ?string $eventFilter = null;

    public ?int $causerIdFilter = null;

    public int $perPage = 50;

    public static function canAccess(): bool
    {
        return (bool) config('books.enabled')
            && (bool) auth()->user()?->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Match the CompaniesLanding pattern: 404 when feature flag is off,
     * 403 when authenticated user is not a super_admin.
     */
    public function mountCanAuthorizeAccess(): void
    {
        if (! (bool) config('books.enabled')) {
            abort(404);
        }

        abort_unless((bool) auth()->user()?->isSuperAdmin(), 403);
    }

    public function getActivities()
    {
        $q = Activity::query()
            ->where('log_name', 'books')
            ->with(['subject', 'causer'])
            ->latest('id');

        if ($this->subjectTypeFilter) {
            $q->where('subject_type', $this->subjectTypeFilter);
        }
        if ($this->eventFilter) {
            $q->where('event', $this->eventFilter);
        }
        if ($this->causerIdFilter) {
            $q->where('causer_id', $this->causerIdFilter)
                ->where('causer_type', \App\Models\User::class);
        }

        return $q->paginate($this->perPage)->withQueryString();
    }

    public function getSubjectTypeOptions(): array
    {
        $types = Activity::where('log_name', 'books')
            ->distinct()
            ->pluck('subject_type')
            ->filter()
            ->values()
            ->toArray();

        return collect($types)
            ->mapWithKeys(fn ($t) => [$t => class_basename($t)])
            ->toArray();
    }

    public function getEventOptions(): array
    {
        return [
            'created'  => 'Created',
            'updated'  => 'Updated',
            'deleted'  => 'Deleted',
            'restored' => 'Restored',
        ];
    }

    public function getCauserOptions(): array
    {
        $ids = Activity::where('log_name', 'books')
            ->whereNotNull('causer_id')
            ->where('causer_type', \App\Models\User::class)
            ->distinct()
            ->pluck('causer_id')
            ->toArray();

        return \App\Models\User::whereIn('id', $ids)
            ->pluck('email', 'id')
            ->toArray();
    }

    public function clearFilters(): void
    {
        $this->subjectTypeFilter = null;
        $this->eventFilter = null;
        $this->causerIdFilter = null;
    }

    public function updatedSubjectTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCauserIdFilter(): void
    {
        $this->resetPage();
    }
}
