<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Silence PHP 8.5+ deprecation noise from Laravel 11's PDO::MYSQL_ATTR_SSL_CA
// (vendor framework config/database.php still uses the pre-namespaced constant);
// without this, deprecation HTML leaks into responses like /livewire/livewire.js
// and corrupts the Livewire bundle so wire:submit fails silently. Real errors
// still surface via Laravel's exception handler.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
