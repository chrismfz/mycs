<?php

use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\CreditController;
use App\Http\Controllers\Api\Admin\InvoiceController;
use App\Http\Controllers\Api\Admin\InvoiceItemController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ServiceController;
use App\Http\Controllers\Api\Admin\TicketController;
use App\Http\Controllers\Api\Admin\TicketMessageController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\DomainPricingController;
use App\Http\Controllers\Api\EkdosiBridgeController;
use Illuminate\Support\Facades\Route;

// The domain price list for the company's site (D8a): public, cached, 60 a minute per address.
Route::get('/v1/domain-pricing', DomainPricingController::class)->middleware('throttle:domain-pricing')->name('api.v1.domain-pricing');

// The Ekdosi bridge (docs/ekdosi-bridge.md): signed with the shared secret, off unless set on; 120 a minute per address.
Route::post('/ekdosi', EkdosiBridgeController::class)->middleware('throttle:120,1')->name('api.ekdosi');

// Rate limited per key (AUTH-17): a leaked or looping key cannot hammer the admin API.
Route::group(['middleware' => ['throttle:admin-api', 'api.admin'], 'prefix' => 'v1/admin', 'as' => 'api.v1.admin.'], function () {
    Route::apiResources([
        'categories' => CategoryController::class,
        'credits' => CreditController::class,
        'clients' => ClientController::class,
        'users' => UserController::class,
        'products' => ProductController::class,
        'services' => ServiceController::class,
        'orders' => OrderController::class,
        'invoices' => InvoiceController::class,
        'invoice-items' => InvoiceItemController::class,
        'tickets' => TicketController::class,
        'ticket-messages' => TicketMessageController::class,
    ]);
});
