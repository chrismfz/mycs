<?php

namespace App\Providers\Filament;

use App\Admin\Auth\AppAuthentication;
use App\Admin\Auth\EditProfile;
use App\Admin\Auth\Login;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Middleware\AdminLocale;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\LockSession;
use App\Http\Middleware\TrackStaffSession;
use App\Http\Middleware\UseStaffGuard;
use App\Models\Extension;
use App\Models\Staff;
use App\Providers\SettingsProvider;
use App\Support\StaffChat;
use App\Support\StaffPresence;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Notifications\Livewire\Notifications;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
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
    /**
     * Where the admin lives: /sysadmin, as in the company's other two systems (owner 2026-10-21). The old /admin
     * redirects here (routes/web.php), so bookmarks and links in mails already sent still open.
     */
    public const PATH = 'sysadmin';

    /** Rows per page of the admin's tables (WHMCS shows long lists). */
    private const PAGE_OPTIONS = [25, 50, 100, 200];

    public function panel(Panel $panel): Panel
    {
        // Filament loads before the settings provider, so we need to load the settings here
        SettingsProvider::getSettings();

        Notifications::alignment(Alignment::Center);

        $panel
            ->default()
            ->id('admin')
            ->path(self::PATH)
            // Staff only, with their own guard, login, password reset and 2FA, which every staff member sets up before anything
            // else in the admin (docs/accounts.md, PR 1; security audit, decided 2026-10-22).
            ->authGuard('staff')
            ->authPasswordBroker('staff')
            ->login(Login::class)
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            // A resource or relation manager with no policy (or no method for the action) is refused, never opened by
            // default (security audit SA-12).
            ->strictAuthorization()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            // Required in the routes, decided per request by the setting «Require 2FA for staff» (StaffTwoFactor).
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffTwoFactor::class)
            ->spa()
            // Density, closer to WHMCS (docs/admin-ux.md): the whole width of the screen, a sidebar that folds away
            // (the top menu has the same pages), tighter spacing in density.css.
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            // 50 rows a page. A table that chooses its own options (widgets, logs under a record) keeps its default.
            // One way to write a date in the whole admin, as in Greece (owner 2026-10-21: lists said «Oct 20, 2026», orders
            // «2026-10-18 21:01:40», a client's page «15/10/2026»), in the timezone of Settings → General.
            ->bootUsing(function () {
                Table::configureUsing(fn (Table $table) => $table
                    ->paginationPageOptions(self::PAGE_OPTIONS)
                    ->defaultPaginationPageOption(fn () => $table->getPaginationPageOptions() === self::PAGE_OPTIONS ? 50 : null)
                    ->defaultDateDisplayFormat('d/m/Y')->defaultDateTimeDisplayFormat('d/m/Y H:i'));
                Schema::configureUsing(fn (Schema $schema) => $schema->defaultDateDisplayFormat('d/m/Y')->defaultDateTimeDisplayFormat('d/m/Y H:i'));
            })
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
            // The sidebar for the day's work (AdminSidebar); the full menu stays below it for a phone (docs/admin-ux.md).
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => view('filament.admin.sidebar-work')->render(),
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => Blade::render('<x-admin-footer />'),
            )
            // The heartbeat of «Staff online» on every page of the admin (docs/livechat.md §7α, §7β), for staff past the
            // mandatory 2FA set-up only (StaffPresence::mayBeOnline, which the heartbeat checks again on every beat).
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => ($staff = auth('staff')->user()) instanceof Staff && StaffPresence::mayBeOnline($staff)
                    ? Blade::render("@livewire('admin.staff-heartbeat')")
                        // The staff chat's sound, notification and count in the tab's title (§7γ), for those in the chat.
                        . (StaffChat::canView($staff) ? Blade::render("@livewire('admin.staff-chat-notifier')") : '')
                    : '',
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
            ->middleware([UseStaffGuard::class, LockSession::class, TrackStaffSession::class, AdminLocale::class], isPersistent: true)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->authMiddleware([
                Authenticate::class,
            ])
            // Ticket attachments for staff: the client area's route is the customers' guard.
            ->authenticatedRoutes(function () {
                Route::get('ticket-attachments/{attachment:uuid}', [TicketAttachmentController::class, 'download'])
                    ->name('ticket-attachments.show')->middleware([EnsureStaffTwoFactor::class, 'can:view,attachment']);
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
