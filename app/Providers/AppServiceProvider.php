<?php

namespace App\Providers;

use App\Classes\Synths\PriceSynth;
use App\Exceptions\ErrorHandler;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Extension;
use App\Models\OauthClient;
use App\Models\User;
use App\Services\Money\GatewayLog;
use App\Support\BootFailure;
use App\Support\Passport\ScopeRegistry;
use Closure;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Exception;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\CommonMark\Extension\Table\TableExtension;
use Livewire\Livewire;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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

            if ($route && $route->named('paymenter.livewire.update')) {
                $previousUrl = url()->previous();

                return $previousUrl !== null ? $previousUrl : request()->fullUrl();
            }

            return request()->fullUrl();
        });

        Request::macro('livewireRoute', function () {
            // Return name of current route
            if (request()->route()->named('paymenter.livewire.update')) {
                $previousUrl = url()->previous();

                if ($previousUrl !== null) {
                    $previousRequest = \Route::getRoutes()->match(request()->create($previousUrl));
                    if ($previousRequest) {
                        return $previousRequest->getName();
                    }
                }

                return 'paymenter.livewire.update';
            }

            return request()->route()->getName();
        });

        $this->app->singleton(Handler::class, function ($app) {
            return new ErrorHandler($app);
        });

        // The gateway log (MT-2a) lives for one request (or one Octane request).
        $this->app->scoped(GatewayLog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Change livewire url
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/paymenter/update', $handle)->middleware('web')->name('paymenter.');
        });
        Livewire::propertySynthesizer(PriceSynth::class);

        Gate::define('has-permission', function (User $user, string $ability) {
            return $user->hasPermission($ability);
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

        Gate::defaultDenialResponse(Response::denyAsNotFound());

        try {
            foreach (
                collect(Extension::where(function ($query) {
                    $query->where('enabled', true)->orWhere('type', 'server')->orWhere('type', 'gateway');
                })->get())->unique('extension') as $extension
            ) {
                ExtensionHelper::call($extension, 'boot', mayFail: true);
            }
        } catch (Exception $e) {
            // The extension list itself (one extension failing to boot is reported by ExtensionHelper::call): no
            // gateway listeners at all, so fatal where it matters (BootFailure).
            BootFailure::handle($e);
        }

        Queue::after(function (JobProcessed $event) {
            if ($event->job->resolveName() === 'App\Mail\Mail') {
                $payload = json_decode($event->job->getRawBody());
                $data = unserialize($payload->data->command);
                EmailLog::where('id', $data->mailable->email_log_id)->update([
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);
            }
        });
        Queue::failing(function (JobFailed $event) {
            if ($event->job->resolveName() === 'App\Mail\Mail') {
                $payload = json_decode($event->job->getRawBody());
                $data = unserialize($payload->data->command);
                EmailLog::where('id', $data->mailable->email_log_id)->update([
                    'status' => 'failed',
                    'error' => $event->exception->getMessage(),
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
}
