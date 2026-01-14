<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
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
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->brandLogo(asset('images/logo_white.svg'))
            ->darkModeBrandLogo(asset('images/logo_dark.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/favicon.svg'))
            ->font('Open Sans')
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                // Costeño Brand - Primary: Cream (#EBDFC7)
                'primary' => [
                    50 => '#FDFCFA',
                    100 => '#FAF7F2',
                    200 => '#F5EFE6',
                    300 => '#EBDFC7', // CREAM_OFFICIAL
                    400 => '#E0D4B8',
                    500 => '#D4C9A9',
                    600 => '#B8A88E',
                    700 => '#968568',
                    800 => '#746550',
                    900 => '#584C3D',
                    950 => '#3D352A',
                ],
                // Gray scale → Navy tones (Navy Official: #191731)
                'gray' => [
                    50 => '#FDFCFA',   // Light backgrounds (cream tint)
                    100 => '#F5EFE6',
                    200 => '#E0D4B8',
                    300 => '#A8A8C0',
                    400 => '#6B6B90',
                    500 => '#3D3870',
                    600 => '#2A2650',
                    700 => '#1E1B3D',
                    800 => '#191731', // NAVY_OFFICIAL
                    900 => '#121025',
                    950 => '#0D0F1A', // NAVY_DARKER
                ],
                // Warning/Accent: Gold (#C5A059)
                'warning' => [
                    50 => '#FDF9F0',
                    100 => '#FAF0DC',
                    200 => '#F5E0B8',
                    300 => '#EDCB8A',
                    400 => '#D4AD6A',
                    500 => '#C5A059', // GOLD_ACCENT
                    600 => '#A88545',
                    700 => '#8A6A36',
                    800 => '#6B512A',
                    900 => '#4D3A1F',
                    950 => '#2F2313',
                ],
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->navigationGroups([
                NavigationGroup::make('Tesorería')
                    ->collapsible(),
                NavigationGroup::make('Administración')
                    ->collapsible(),
            ])
            ->pages([])
            ->homeUrl('/admin/branches')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
