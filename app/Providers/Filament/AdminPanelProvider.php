<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
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
            ->font('Inter')
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                // Costeño Luxury UI - Primary: Cream Champagne
                'primary' => [
                    50 => '#FDFCFA',
                    100 => '#FAF8F4',
                    200 => '#F5F1EA',
                    300 => '#EDE7DC',
                    400 => '#E6DFD1', // CREAM_PRIMARY
                    500 => '#D4C9B5',
                    600 => '#B8A88E',
                    700 => '#968568',
                    800 => '#746550',
                    900 => '#584C3D',
                    950 => '#3D352A',
                ],
                // Gray scale → Navy tones (Costeño Navy)
                'gray' => [
                    50 => '#F4F4F8',   // Light mode backgrounds
                    100 => '#E8E8F0',
                    200 => '#D1D1E0',
                    300 => '#A8A8C0',
                    400 => '#7070A0',
                    500 => '#4A4A78',
                    600 => '#2E2E58',
                    700 => '#1A1A40',
                    800 => '#121230',
                    900 => '#0B0E21',  // NAVY_BASE
                    950 => '#080A19',  // NAVY_DARKER
                ],
                // Warning/Accent: Gold
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
            ->pages([
                Dashboard::class,
            ])
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
