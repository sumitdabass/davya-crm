<?php

namespace App\Providers;

use App\Listeners\HandleAuthEvents;
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
        $this->app->singleton(\App\Services\Finance\GeminiClient::class, function () {
            return new \App\Services\Finance\GeminiClient(
                apiKey: (string) config('finance.assistant.gemini_api_key', ''),
                model:  (string) config('finance.assistant.model', 'gemini-2.5-flash'),
                timeoutSeconds: (int) config('finance.assistant.gemini_timeout_seconds', 30),
            );
        });

        $this->app->singleton(\App\Services\Finance\AssistantAnswerer::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class,  [HandleAuthEvents::class, 'handleLogin']);
        Event::listen(Logout::class, [HandleAuthEvents::class, 'handleLogout']);
        Event::listen(Failed::class, [HandleAuthEvents::class, 'handleFailed']);

        Storage::extend('google', function ($app, $config) {
            $options = [];
            if (! empty($config['teamDriveId'] ?? null)) {
                $options['teamDriveId'] = $config['teamDriveId'];
            }

            $client = new \Google\Client();
            $client->setClientId($config['clientId']);
            $client->setClientSecret($config['clientSecret']);
            $client->refreshToken($config['refreshToken']);

            $service = new \Google\Service\Drive($client);
            $adapter = new GoogleDriveAdapter($service, $config['folderId'] ?? 'root', $options);

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        \App\Models\Meeting::observe(\App\Observers\MeetingObserver::class);
        \App\Models\Student::observe(\App\Observers\StudentObserver::class);
        \App\Models\Payment::observe(\App\Observers\PaymentObserver::class);
        \App\Models\RoundHistory::observe(\App\Observers\RoundHistoryObserver::class);
        \App\Models\StudentNote::observe(\App\Observers\StudentNoteObserver::class);
        \App\Models\StudentFieldValue::observe(\App\Observers\StudentFieldValueObserver::class);
    }
}
