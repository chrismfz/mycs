<?php

use App\Http\Controllers\SocialLoginController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\WhmcsLinkController;
use App\Http\Middleware\MustVerfiyEmail;
use App\Livewire\Auth;
use App\Livewire\Cart;
use App\Livewire\Client;
use App\Livewire\Dashboard;
use App\Livewire\Domains;
use App\Livewire\DomainShop;
use App\Livewire\Home;
use App\Livewire\Invoices;
use App\Livewire\Products;
use App\Livewire\Services;
use App\Livewire\Tickets;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;

Route::get('/', Home::class)->name('home');
// The shop's domains (D8a): search, whois and price list, public.
Route::get('/domain-search', DomainShop\Search::class)->name('domain.search');
Route::get('/whois', DomainShop\Whois::class)->name('domain.whois');
Route::get('/domain-pricing', DomainShop\Pricing::class)->name('domain.pricing');

// Destroy the session and log out the user.
// auth()->logout();
// Authorization routes
Route::group(['middleware' => ['web', 'guest']], function () {
    Route::get('/login', Auth\Login::class)->name('login');
    Route::get('/2fa', Auth\Tfa::class)->name('2fa');
    Route::get('/register', Auth\Register::class)->name('register');
    // Todo
    Route::get('/password/request', Auth\Password\Request::class)->name('password.request');
    Route::get('/password/reset/{token}', Auth\Password\Reset::class)->name('password.reset');

    Route::get('/oauth/{provider}', [SocialLoginController::class, 'redirect'])->name('oauth.redirect');
    Route::get('/oauth/{provider}/callback', [SocialLoginController::class, 'handle'])->name('oauth.handle');
});

// Each area of the current account needs the login's grant to allow it (docs/accounts.md, PR 2b): `client.can`, and
// the policies for a record reached by its URL.
Route::group(['middleware' => ['web', 'auth', MustVerfiyEmail::class]], function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/invoices', Invoices\Index::class)->name('invoices')->middleware('client.can:invoices');
    // Mass Pay (PR F2): the account's chosen invoices, paid at once.
    Route::get('/invoices/pay-together/{paymentGroup}', Invoices\PayTogether::class)->name('invoices.pay-together')->middleware('can:view,paymentGroup');
    Route::get('/invoices/{invoice}', Invoices\Show::class)->name('invoices.show')->middleware('can:view,invoice');

    Route::get('/tickets', Tickets\Index::class)->name('tickets')->middleware('client.can:tickets');
    Route::get('/tickets/create', Tickets\Create::class)->name('tickets.create')->middleware('client.can:tickets');
    Route::get('/tickets/{ticket}', Tickets\Show::class)->name('tickets.show')->middleware('can:view,ticket');

    Route::get('/services', Services\Index::class)->name('services')->middleware('client.can:products');
    Route::get('/services/{service}', Services\Show::class)->name('services.show')->middleware('can:view,service');
    // The customer's domains (D7-2).
    Route::get('/domains', Domains\Index::class)->name('domains')->middleware('client.can:domains');
    Route::get('/domains/{domain}', Domains\Show::class)->name('domains.show')->middleware('can:view,domain');
    // The account's users (docs/accounts.md, PR 2b): it mails invitations, so only a verified login.
    Route::get('/account/users', Client\Users::class)->name('account.users');

    Route::get('/services/{service}/upgrade', Services\Upgrade::class)->name('services.upgrade')->middleware('can:update,service');
});

Route::group(['middleware' => ['web', 'auth']], function () {
    Route::get('/account', Client\Account::class)->name('account');
    Route::get('/account/security', Client\Security::class)->name('account.security');
    Route::get('/account/credits', Client\Credits::class)->name('account.credits')->middleware('client.can:invoices');
    Route::get('/account/payment-methods', Client\PaymentMethods::class)->name('account.payment-methods')->middleware('client.can:invoices');
    Route::get('/account/notifications', Client\Notifications::class)->name('account.notifications');
    Route::get('/account/accounts', Client\Accounts::class)->name('account.accounts');
    Route::get('/account/contacts', Client\Contacts::class)->name('account.contacts')->middleware('client.can:contacts');

    Route::get('/email/verify', Auth\VerifyEmail::class)->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('dashboard');
    })->middleware(['signed'])->name('verification.verify');
    Route::get('/tickets/attachments/{attachment:uuid}', [TicketAttachmentController::class, 'download'])->name('tickets.attachments.show')->middleware('can:view,attachment');
});

// An invitation to an account: a guest is asked to log in or register first (docs/accounts.md, PR 2b).
Route::get('/invite/{token}', Client\AcceptInvite::class)->name('invites.accept')->middleware(['web', 'throttle:30,1']);

Route::get('cart', Cart::class)->name('cart')->middleware('checkout');

// The WHMCS links of the companies' sites and old emails (docs/admin-ux.md, «Απευθείας links»): at the root, as
// clients.nixpal.com, and under /clients, as myip.gr/clients, so that at the cut-over only their host changes.
foreach (['' => 'whmcs.', 'clients' => 'whmcs.clients.'] as $prefix => $name) {
    Route::prefix($prefix)->name($name)->group(function () use ($prefix) {
        Route::get('cart.php', [WhmcsLinkController::class, 'cart'])->name('cart');
        Route::get('store/{group}/{product?}', [WhmcsLinkController::class, 'store'])->name('store');
        Route::get('clientarea.php', [WhmcsLinkController::class, 'clientArea'])->name('clientarea');
        Route::get('viewinvoice.php', [WhmcsLinkController::class, 'invoice'])->name('viewinvoice');
        Route::redirect('register.php', '/register')->name('register');
        Route::redirect('pwreset.php', '/password/request')->name('pwreset');
        Route::redirect('announcements', '/')->name('announcements');
        Route::redirect('domain/pricing', '/domain-pricing')->name('domain-pricing');
        Route::redirect('domainchecker.php', '/domain-search')->name('domainchecker');
        Route::redirect('supporttickets.php', '/tickets')->name('tickets');
        // Until a ticket can be opened without logging in (contact): the new ticket page, after the login.
        Route::redirect('submitticket.php', '/tickets/create')->name('submitticket');
        Route::redirect('contact.php', '/tickets/create')->name('contact');
        Route::redirect('knowledgebase', '/')->name('knowledgebase');
        if ($prefix !== '') {
            Route::redirect('login', '/login')->name('login');
            Route::redirect('/', '/')->name('home');
        }
    });
}

Route::group(['prefix' => 'products', 'middleware' => 'checkout'], function () {
    Route::get('/{category:slug}', Products\Index::class)->name('category.show')/* ->where('category', '[A-Za-z0-9_/-]+') */;
    Route::get('/{category:slug}/{product:slug}', Products\Show::class)->name('products.show')/* ->where('category', '[A-Za-z0-9_/-]+') */;
    Route::get('/{category:slug}/{product:slug}/checkout', Products\Checkout::class)->name('products.checkout')/* ->where('category', '[A-Za-z0-9_/-]+') */;
    // Allow for nested categories
});

Route::group([
    'as' => 'passport.',
    'prefix' => config('passport.path', 'oauth'),
    'namespace' => '\Laravel\Passport\Http\Controllers',
], function () {
    Route::get('/oauth/authorize', [
        'uses' => 'Laravel\Passport\Http\Controllers\AuthorizationController@authorize',
        'as' => 'x.authorize',
        'middleware' => 'web',
    ]);
});
