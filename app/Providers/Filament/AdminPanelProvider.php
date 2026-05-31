<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
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
    public function boot(): void
    {
        FilamentAsset::register([
            Css::make('admin-custom', __DIR__.'/../../../public/css/filament/admin/custom.css')
                ->relativePublicPath('css/filament/admin/custom.css'),
        ]);
    }

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
            ->font('Geist', provider: GoogleFontProvider::class)
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                'primary' => Color::Slate,
                'gray' => Color::Slate,
            ])
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->navigationGroups([
                NavigationGroup::make('Tesorería')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->collapsible(),
                NavigationGroup::make('SAP')
                    ->icon(Heroicon::OutlinedServerStack)
                    ->collapsible(),
                NavigationGroup::make('IA')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->collapsible(),
                NavigationGroup::make('Administración')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->collapsible()
                    ->collapsed(),
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
            ->resourceCreatePageRedirect('index')
            ->resourceEditPageRedirect('index')
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Administración'),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
