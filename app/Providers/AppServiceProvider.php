<?php

namespace App\Providers;

use App\Classes\Synths\PriceSynth;
use App\Console\Commands\MailSweep;
use App\Exceptions\ErrorHandler;
use App\Helpers\ExtensionHelper;
use App\Http\Middleware\EnsureClientPermission;
use App\Http\Middleware\StartSessionWithoutPrivateLinks;
use App\Mail\Mail;
use App\Mail\ResentMail;
use App\Mail\SystemMail;
use App\Models\ApiKey;
use App\Models\EmailLog;
use App\Models\Extension;
use App\Models\OauthClient;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\Lookup\VisitorKey;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Money\GatewayLog;
use App\Support\BootFailure;
use App\Support\CurrentClient;
use App\Support\Domains\RegistrarTransactions;
use App\Support\Passport\ScopeRegistry;
use Closure;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Exception;
use Filament\Forms\Components\FileUpload;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\CommonMark\Extension\Table\TableExtension;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\ImapEngineMailbox;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\Vies\CurlTransport;
use MyCS\Vies\Vies;
use Nixpal\DomainTools\Whois\SocketConnector;
use Nixpal\DomainTools\Whois\StreamSocketConnector;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Port 43 whois (D8a): the real socket; tests bind their own.
        $this->app->bind(SocketConnector::class, StreamSocketConnector::class);
        // The registrar transactions of this process (clTRID, svTRID: kept on each call's log).
        $this->app->singleton(RegistrarTransactions::class);

        // Service provider for settings
        $this->app->register(SettingsProvider::class);

        UrlGenerator::macro('alternateHasCorrectSignature', function (Request $request, $absolute = true, Closure|array $ignoreQuery = []) {
            // ensure the base path is applied to absolute url
            $absoluteUrl = url($request->path()); // forceRootUrl and forceScheme will apply
            $url = $absolute ? $absoluteUrl : '/' . $request->path();

            $queryString = collect(explode('&', (string) $request->server->get('QUERY_STRING')))
                ->reject(function ($parameter) use ($ignoreQuery) {
                    $parameter = Str::before($parameter, '=');

                    if ($parameter === 'signature') {
                        return true;
                    }

                    if ($ignoreQuery instanceof Closure) {
                        return $ignoreQuery($parameter);
                    }

                    return in_array($parameter, $ignoreQuery);
                })
                ->join('&');

            $original = rtrim($url . '?' . $queryString, '?');

            $keys = call_user_func($this->keyResolver);

            $keys = is_array($keys) ? $keys : [$keys];

            foreach ($keys as $key) {
                if (
                    hash_equals(
                        hash_hmac('sha256', $original, $key),
                        (string) $request->query('signature', '')
                    )
                ) {
                    return true;
                }
            }

            return false;
        });

        UrlGenerator::macro('alternateHasValidSignature', function (Request $request, $absolute = true, array $ignoreQuery = []) {
            return \URL::alternateHasCorrectSignature($request, $absolute, $ignoreQuery)
                && \URL::signatureHasNotExpired($request);
        });

        Request::macro('hasValidSignature', function ($absolute = true, array $ignoreQuery = []) {
            return \URL::alternateHasValidSignature($this, $absolute, $ignoreQuery);
        });

        Request::macro('livewireUrl', function () {
            // Somehow people manage to have no route
            $route = request()->route();

            if ($route && $route->named('mycs.livewire.update')) {
                $previousUrl = url()->previous();

                return $previousUrl !== null ? $previousUrl : request()->fullUrl();
            }

            return request()->fullUrl();
        });

        Request::macro('livewireRoute', function () {
            // Return name of current route
            if (request()->route()->named('mycs.livewire.update')) {
                $previousUrl = url()->previous();

                if ($previousUrl !== null) {
                    $previousRequest = \Route::getRoutes()->match(request()->create($previousUrl));
                    if ($previousRequest) {
                        return $previousRequest->getName();
                    }
                }

                return 'mycs.livewire.update';
            }

            return request()->route()->getName();
        });

        $this->app->singleton(Handler::class, function ($app) {
            return new ErrorHandler($app);
        });

        // The gateway log (MT-2a) lives for one request (or one Octane request).
        $this->app->scoped(GatewayLog::class);
        $this->app->scoped(CurrentClient::class);
        // VIES (PR G): with the instance's own VAT number as requester, VIES gives the consultation number.
        $this->app->bind(Mailbox::class, ImapEngineMailbox::class);
        // The session never keeps a private link's URL (docs/tickets.md §3 Δ).
        $this->app->bind(StartSession::class, StartSessionWithoutPrivateLinks::class);
        $this->app->bind(Vies::class, fn () => new Vies(new CurlTransport, config('settings.eu_vat_requester')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // .gr registry sessions end with the request or command (a worker keeps its own: it runs on): review 6 of #187.
        $this->app->terminating(fn () => GrEppRegistrar::reset());
        // A file field takes a new upload or a path its record already has, never one the browser names: the state is
        // the client's, and a path in it would reach storage as is. Settings, with no record, name theirs (FilamentInput).
        FileUpload::configureUsing(fn (FileUpload $upload) => $upload->preventFilePathTampering());
        // The public price list API (D8a): 60 a minute per caller's own address (review R2, App\Support\CallerIp).
        RateLimiter::for('domain-pricing', fn ($request) => Limit::perMinute(60)->by(VisitorKey::of($request)));
        // Our captcha's challenges (docs/captcha.md): a bucket of their own, not shared with other by-IP limits.
        RateLimiter::for('altcha', fn ($request) => Limit::perMinute(30)->by('altcha:' . VisitorKey::of($request)));

        // The admin API (AUTH-17): 120 calls a minute per key that exists and is enabled; anything else (no token, a
        // junk one) shares 60 a minute per address, so a new junk token on every call gets no fresh bucket (R1 of #90).
        RateLimiter::for('admin-api', function (Request $request) {
            $hash = $request->bearerToken() ? hash('sha256', $request->bearerToken()) : null;
            if ($hash !== null && ApiKey::query()->where('token', $hash)->where('enabled', true)->exists()) {
                return Limit::perMinute(120)->by('key:' . $hash);
            }

            return Limit::perMinute(60)->by('bad:' . $request->ip());
        });

        // Change livewire url
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/mycs/update', $handle)->middleware('web')->name('mycs.');
        });
        Livewire::propertySynthesizer(PriceSynth::class);
        // A page's area check holds for its Livewire calls too, not only the first load (docs/accounts.md, PR 2b).
        Livewire::addPersistentMiddleware([EnsureClientPermission::class]);

        // Only staff hold admin permissions (docs/accounts.md, PR 1); a customer never does.
        Gate::define('has-permission', function (User|Staff $user, string $ability) {
            return $user instanceof Staff && $user->hasPermission($ability);
        });

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', Provider::class);
        });

        // The gateway log (docs/money-trail.md §4, MT-2a): every request to a gateway's routes, without code in the
        // gateways (their routes are registered in their own boot, outside any middleware group).
        Event::listen(function (RouteMatched $event) {
            $name = (string) $event->route->getName();
            if (str_starts_with($name, 'extensions.gateways.')) {
                GatewayLog::current()->start($event->request, $name);
            }
        });
        Event::listen(function (RequestHandled $event) {
            $log = GatewayLog::current();
            // A request to a gateway's URLs that matched no route (a wrong method or path) shows too (MT-3).
            if (!$log->active() && in_array($event->response->getStatusCode(), [404, 405], true)
                && ($gateway = self::gatewayOfPath($event->request)) !== null) {
                $log->unmatched($event->request, $event->response, $gateway);
            }
            $log->finish($event->response);
        });

        // When a member of staff last logged in (the staff list shows it).
        Event::listen(function (Login $event) {
            if ($event->guard === 'staff' && $event->user instanceof Staff) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        Gate::defaultDenialResponse(Response::denyAsNotFound());

        try {
            foreach (ExtensionHelper::bootable() as $extension) {
                ExtensionHelper::call($extension, 'boot', mayFail: true);
            }
        } catch (Exception $e) {
            // The extension list itself (one extension failing to boot is reported by ExtensionHelper::call): no
            // gateway listeners at all, so fatal where it matters (BootFailure).
            BootFailure::handle($e);
        }

        // The mail audit (docs/mail.md): the send keeps its row (LogsDelivery: sending, sent, refused); the queue's last
        // failure marks it failed, never over a sent one.
        Queue::failing(function (JobFailed $event) {
            if (in_array($event->job->resolveName(), [Mail::class, SystemMail::class, ResentMail::class], true)) { // EVT-10: system mails too
                $data = self::queuedCommand($event->job->getRawBody());
                $id = $data->mailable->email_log_id;
                // Left «sending» (the worker died or timed out mid-send): it may have gone out; kept so (sending_at),
                // never claimed nor batch-resent again (review R6).
                EmailLog::where('id', $id)->where('status', EmailLog::STATUS_SENDING)->update([
                    'status' => EmailLog::STATUS_FAILED,
                    'failed_at' => now(),
                    'error' => MailSweep::MAY_HAVE_GONE_OUT,
                    'job_uuid' => $event->job->uuid(),
                ]);
                EmailLog::where('id', $id)->where('status', EmailLog::STATUS_PENDING)->update([
                    'status' => EmailLog::STATUS_FAILED,
                    'failed_at' => now(),
                    'error' => mb_substr($event->exception->getMessage(), 0, 1000),
                    'job_uuid' => $event->job->uuid(),
                ]);
            }
        });
        // The audit is off in the console (config/audit.php): seeding and the WHMCS importer write no audit rows. A
        // queued job is the app at work (a cancellation request, provisioning), audited like a web request
        // (docs/money-trail.md §3, MT-2b). Put back after the job: a command that runs a job inline stays as it was.
        $auditing = [];
        Queue::before(function () use (&$auditing) {
            $auditing[] = config('audit.console');
            config(['audit.console' => true]);
        });
        $restore = function () use (&$auditing) {
            if ($auditing !== []) {
                config(['audit.console' => array_pop($auditing)]);
            }
        };
        Event::listen(JobAttempted::class, $restore); // after every attempt, whatever happened (finally)

        Str::macro('markdown', function ($markdown) {
            return Str::markdown($markdown, extensions: [
                new TableExtension,
            ]);
        });
        Passport::useClientModel(OauthClient::class);
        Passport::ignoreRoutes();
        Passport::tokensCan(ScopeRegistry::getAll());
        Passport::authorizationView('vendor.passport.authorize');
        Passport::$validateKeyPermissions = false;

        if (class_exists(Scramble::class)) {
            Scramble::configure()
                ->routes(function (\Illuminate\Routing\Route $route) {
                    return Str::startsWith($route->uri, 'api/v1/admin');
                })
                ->withDocumentTransformers(function (OpenApi $openApi) {
                    $openApi->secure(
                        SecurityScheme::http('bearer')
                    );
                });
        }
    }

    /**
     * The gateway extension a URL belongs to: /extensions/{gateway}/… or /extensions/gateways/{gateway}/…, the paths the
     * gateways register their routes under. Null for anything else.
     */
    private static function gatewayOfPath(Request $request): ?string
    {
        if ($request->segment(1) !== 'extensions') {
            return null;
        }
        $name = strtolower((string) ($request->segment(2) === 'gateways' ? $request->segment(3) : $request->segment(2)));
        try {
            $gateways = Extension::query()->where('type', 'gateway')->pluck('extension')->map(fn ($extension) => strtolower((string) $extension));
        } catch (\Throwable $e) {
            return null;
        }

        return $name !== '' && $gateways->contains($name) ? $name : null;
    }

    /**
     * A queued job's command, as the worker reads it (CallQueuedHandler): mails are encrypted in the queue (sweep §6 #11).
     */
    private static function queuedCommand(string $rawBody): object
    {
        $command = json_decode($rawBody)->data->command;

        return unserialize(str_starts_with($command, 'O:') ? $command : app(Encrypter::class)->decrypt($command));
    }
}
