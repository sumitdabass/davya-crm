<?php

namespace App\Providers;

use App\Listeners\HandleAuthEvents;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentFieldValue;
use App\Models\StudentNote;
use App\Observers\MeetingObserver;
use App\Observers\PaymentObserver;
use App\Observers\RoundHistoryObserver;
use App\Observers\StudentFieldValueObserver;
use App\Observers\StudentNoteObserver;
use App\Observers\StudentObserver;
use App\Observers\StudentRankProbabilityObserver;
use App\Services\Finance\AssistantAnswerer;
use App\Services\Finance\GeminiClient;
use Filament\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Google\Client;
use Google\Service\Drive;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class, function () {
            return new GeminiClient(
                apiKey: (string) config('finance.assistant.gemini_api_key', ''),
                model: (string) config('finance.assistant.model', 'gemini-2.5-flash'),
                timeoutSeconds: (int) config('finance.assistant.gemini_timeout_seconds', 30),
            );
        });

        $this->app->singleton(AssistantAnswerer::class);

        $this->app->singleton(\App\Services\Ai\Tools\SearchPagesTool::class, fn() => new \App\Services\Ai\Tools\SearchPagesTool(
            config('ai.ipu_docroot'),
            config('ai.excluded_dirs'),
        ));
        $this->app->singleton(\App\Services\Ai\Tools\ReadPageTool::class, fn() => new \App\Services\Ai\Tools\ReadPageTool(
            config('ai.ipu_docroot'),
            (int) config('ai.read_page_byte_cap'),
        ));
        $this->app->bind(\App\Services\Ai\LlmProvider::class, function () {
            $cfg = config('ai.providers.groq');
            return new \App\Services\Ai\Providers\GroqProvider(
                apiKey: $cfg['key'] ?? '',
                model: $cfg['model'],
                baseUrl: $cfg['base_url'],
                timeoutSeconds: $cfg['timeout_seconds'],
            );
        });
        $this->app->bind(\App\Services\Ai\AssistantService::class, fn($app) => new \App\Services\Ai\AssistantService(
            provider: $app->make(\App\Services\Ai\LlmProvider::class),
            search: $app->make(\App\Services\Ai\Tools\SearchPagesTool::class),
            read: $app->make(\App\Services\Ai\Tools\ReadPageTool::class),
            maxRoundTrips: (int) config('ai.max_tool_roundtrips'),
            historyTurns: (int) config('ai.max_history_turns'),
        ));
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\AiConversation::class,
            \App\Policies\AiConversationPolicy::class,
        );

        Event::listen(Login::class, [HandleAuthEvents::class, 'handleLogin']);
        Event::listen(Logout::class, [HandleAuthEvents::class, 'handleLogout']);
        Event::listen(Failed::class, [HandleAuthEvents::class, 'handleFailed']);

        Storage::extend('google', function ($app, $config) {
            $options = [];
            if (! empty($config['teamDriveId'] ?? null)) {
                $options['teamDriveId'] = $config['teamDriveId'];
            }

            $client = new Client;
            $client->setClientId($config['clientId']);
            $client->setClientSecret($config['clientSecret']);
            $client->refreshToken($config['refreshToken']);

            $service = new Drive($client);
            $adapter = new GoogleDriveAdapter($service, $config['folderId'] ?? 'root', $options);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        Meeting::observe(MeetingObserver::class);
        Student::observe(StudentObserver::class);
        Student::observe(StudentRankProbabilityObserver::class);
        Payment::observe(PaymentObserver::class);
        RoundHistory::observe(RoundHistoryObserver::class);
        StudentNote::observe(StudentNoteObserver::class);
        StudentFieldValue::observe(StudentFieldValueObserver::class);
        \App\Models\Book\Company::observe(\App\Observers\Book\CompanyObserver::class);

        // Lock every Filament delete action to super_admin. Picks up
        // DeleteAction / DeleteBulkAction wherever they're used (table
        // row, bulk dropdown, edit-page header) without touching each
        // resource individually.
        $superAdminOnly = fn () => auth()->user()?->isSuperAdmin() ?? false;
        \Filament\Tables\Actions\DeleteAction::configureUsing(fn ($action) => $action->visible($superAdminOnly));
        DeleteBulkAction::configureUsing(fn ($action) => $action->visible($superAdminOnly));
        DeleteAction::configureUsing(fn ($action) => $action->visible($superAdminOnly));
    }
}
