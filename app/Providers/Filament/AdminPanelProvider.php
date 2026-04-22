<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Filament\Auth\LockoutLogin::class)
            ->brandName('Davya CRM')
            ->brandLogo(asset('davyas-logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('davyas-logo.png'))
            ->colors([
                'primary' => Color::Emerald,
                'gray'    => Color::Slate,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render(<<<'BLADE'
                <style>
                    /* Narrow the login/challenge card so the giant "Sign in" block feels right-sized. */
                    .fi-simple-main { max-width: 24rem; }
                    /* Slightly tighter main content — matches Bigin's airy-but-dense look. */
                    .fi-main-ctn { padding-top: 1rem; }
                </style>
            BLADE))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\PipelineSummaryWidget::class,
                \App\Filament\Widgets\SeatFeePendingWidget::class,
                \App\Filament\Widgets\ReEntryCandidatesWidget::class,
                \App\Filament\Widgets\StuckLeadsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\AbsoluteSessionTimeout::class,
                \App\Http\Middleware\RequirePasswordChange::class,
                \App\Http\Middleware\RequireTwoFactor::class,
            ]);
    }
}
