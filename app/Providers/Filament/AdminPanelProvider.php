<?php

namespace App\Providers\Filament;

use App\Admin\Auth\EditProfile;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Middleware\AdminLocale;
use App\Http\Middleware\LockSession;
use App\Http\Middleware\UseStaffGuard;
use App\Models\Extension;
use App\Providers\SettingsProvider;
use Exception;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Notifications\Livewire\Notifications;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Filament loads before the settings provider, so we need to load the settings here
        SettingsProvider::getSettings();

        Notifications::alignment(Alignment::Center);

        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Staff only, with their own guard, login, password reset and optional 2FA (docs/accounts.md, PR 1).
            ->authGuard('staff')
            ->authPasswordBroker('staff')
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->spa()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->favicon(config('settings.favicon') ? Storage::url(config('settings.favicon')) : null)
            ->brandLogo(config('settings.logo') ? Storage::url(config('settings.logo')) : null)
            ->darkModeBrandLogo(config('settings.logo_dark') ? Storage::url(config('settings.logo_dark')) : null)
            ->brandName(config('settings.logo') || config('settings.logo_dark') ? null : config('app.name'))
            ->brandLogoHeight('2rem')
            ->discoverResources(in: app_path('Admin/Resources'), for: 'App\\Admin\\Resources')
            ->discoverPages(in: app_path('Admin/Pages'), for: 'App\\Admin\\Pages')
            ->discoverClusters(in: app_path('Admin/Clusters'), for: 'App\\Admin\\Clusters')
            ->userMenuItems([
                'exit_admin' => Action::make('exit_admin')
                    ->label('Exit Admin')
                    ->url('/')
                    ->icon('heroicon-s-arrow-uturn-left'),
            ])
            ->discoverWidgets(in: app_path('Admin/Widgets'), for: 'App\\Admin\\Widgets')
            // WHMCS-like menu next to the logo; the sidebar stays (docs/admin-ux.md).
            ->renderHook(
                PanelsRenderHook::TOPBAR_LOGO_AFTER,
                fn (): string => view('filament.admin.top-menu')->render(),
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => Blade::render('<x-admin-footer />'),
            )
            ->renderHook(
                'panels::head.end',
                fn (): string => view('layouts.colors')->render(),
            )
            ->navigationGroups([
                'Administration',
                'Configuration',
                'Extensions',
                'System',
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Before LockSession and AuthenticateSession: they check the staff member's session (also persistent below).
                UseStaffGuard::class,
                LockSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistent: Livewire updates inside the panel use the admin language too.
            // Persistent: Livewire requests of the panel (its actions, forms, tables) go through /mycs/update, and
            // they too must see the staff member, never a customer logged in in the same browser.
            // LockSession too: the IP/agent lock covers the panel's actions, not only its pages.
            ->middleware([UseStaffGuard::class, LockSession::class, AdminLocale::class], isPersistent: true)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->authMiddleware([
                Authenticate::class,
            ])
            // Ticket attachments for staff: the client area's route is the customers' guard.
            ->authenticatedRoutes(function () {
                Route::get('ticket-attachments/{attachment:uuid}', [TicketAttachmentController::class, 'download'])
                    ->name('ticket-attachments.show')->middleware('can:view,attachment');
            });

        try {
            foreach (collect(Extension::where(function ($query) {
                $query->where('enabled', true)->orWhere('type', 'server')->orWhere('type', 'gateway');
            })->get())->unique('extension') as $extension) {
                $panel->discoverResources(in: base_path('extensions' . '/' . $extension->path . '/Admin/Resources'), for: $extension->namespace . '\\Admin\\Resources');
                $panel->discoverPages(in: base_path('extensions' . '/' . $extension->path . '/Admin/Pages'), for: $extension->namespace . '\\Admin\\Pages');
                $panel->discoverClusters(in: base_path('extensions' . '/' . $extension->path . '/Admin/Clusters'), for: $extension->namespace . '\\Admin\\Clusters');
            }
        } catch (Exception $e) {
            // Do nothing
        }

        return $panel;
    }
}
