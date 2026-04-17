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
        //
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
    }
}
