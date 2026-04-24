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
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => config('davyas.visual_v2')
                    ? '<script>document.body.classList.add("davya-v2")</script>'
                        . Blade::render('@livewire("top-bar") @livewire("command-palette") @livewire("student-peek-drawer")')
                        . <<<'HTML'
                        <script>
                            document.addEventListener('keydown', (e) => {
                                if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                                    e.preventDefault();
                                    window.dispatchEvent(new CustomEvent('open-command-palette'));
                                }
                            });
                            window.addEventListener('open-command-palette', () => {
                                if (window.Livewire) {
                                    window.Livewire.dispatch('open-command-palette');
                                }
                            });
                        </script>
                        HTML
                    : ''
            )
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render(<<<'BLADE'
                <link rel="manifest" href="/manifest.json">
                <meta name="theme-color" content="#065f46">
                <meta name="mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
                <meta name="apple-mobile-web-app-title" content="Davya">
                <link rel="apple-touch-icon" href="/apple-touch-icon.png">
                {{-- SortableJS: single global include for pipeline-config + kanban (+ future pages). --}}
                <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" defer></script>
                @if (config('davyas.visual_v2'))
                    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}" id="davya-tokens">
                @endif
                <style>
                    /* Narrow the login/challenge card so the giant "Sign in" block feels right-sized. */
                    .fi-simple-main { max-width: 24rem; }
                    /* Slightly tighter main content — matches Bigin's airy-but-dense look. */
                    .fi-main-ctn { padding-top: 1rem; }
                </style>
                <script>
                    // Capture beforeinstallprompt as early as possible so Alpine
                    // components (dashboard widget + InstallApp page) can read the
                    // deferred event even if the browser fires it before Alpine mounts.
                    window.__davyaInstallPrompt = null;
                    window.addEventListener('beforeinstallprompt', (e) => {
                        e.preventDefault();
                        window.__davyaInstallPrompt = e;
                    });
                    window.addEventListener('appinstalled', () => {
                        window.__davyaInstallPrompt = null;
                    });

                    if ('serviceWorker' in navigator) {
                        window.addEventListener('load', () => {
                            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
                        });
                    }
                </script>
            BLADE))
            ->userMenuItems([
                \Filament\Navigation\MenuItem::make()
                    ->label('Install app')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (): string => \App\Filament\Pages\InstallApp::getUrl()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\InstallAppWidget::class,
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
