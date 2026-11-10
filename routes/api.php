<?php

use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\CreditController;
use App\Http\Controllers\Api\Admin\InvoiceAdjustmentNoteController;
use App\Http\Controllers\Api\Admin\InvoiceController;
use App\Http\Controllers\Api\Admin\InvoiceItemController;
use App\Http\Controllers\Api\Admin\InvoicePaymentController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ServiceController;
use App\Http\Controllers\Api\Admin\TicketController;
use App\Http\Controllers\Api\Admin\TicketMessageController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\DomainCheckController;
use App\Http\Controllers\Api\DomainPricingController;
use App\Http\Controllers\Api\EkdosiBridgeController;
use App\Http\Controllers\Api\ProxmoxConsoleCheckController;
use Illuminate\Support\Facades\Route;

// The domain price list for the company's site (D8a): public, cached, 60 a minute per address.
Route::get('/v1/domain-pricing', DomainPricingController::class)->middleware('throttle:domain-pricing')->name('api.v1.domain-pricing');
// One name's availability for the company site's live search (WG-20): public, the site's server with its key apart.
Route::get('/v1/domain-check', DomainCheckController::class)->middleware('throttle:domain-check')->name('api.v1.domain-check');

// The Ekdosi bridge (docs/ekdosi-bridge.md): signed with the shared secret, off unless set on; 120 a minute per address.
Route::post('/ekdosi', EkdosiBridgeController::class)->middleware('throttle:120,1')->name('api.ekdosi');

// The console relay's check, every 30 seconds (docs/proxmox.md, «Σχέδιο: το relay στο cluster»): signed with a
// cluster's console key, all of its open consoles in one call; 120 a minute per address.
Route::post('/proxmox/console/check', ProxmoxConsoleCheckController::class)->middleware('throttle:120,1')->name('api.proxmox.console.check');

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

    // An invoice's payments, refunds and adjustment notes, as the panel's tables correct them (owner 2026-11-10,
    // docs/staff-freedom.md): each id belongs to the invoice in the URL (scoped: 404 otherwise).
    Route::scopeBindings()->prefix('invoices/{invoice}')->name('invoices.')->group(function () {
        Route::get('payments', [InvoicePaymentController::class, 'index'])->name('payments.index');
        Route::post('payments', [InvoicePaymentController::class, 'store'])->name('payments.store');
        Route::get('payments/{transaction}', [InvoicePaymentController::class, 'show'])->name('payments.show');
        Route::put('payments/{transaction}', [InvoicePaymentController::class, 'update'])->name('payments.update');
        Route::delete('payments/{transaction}', [InvoicePaymentController::class, 'destroy'])->name('payments.destroy');
        Route::get('payments/{transaction}/refunds', [InvoicePaymentController::class, 'refunds'])->name('payments.refunds.index');
        Route::post('payments/{transaction}/refunds', [InvoicePaymentController::class, 'refund'])->name('payments.refunds.store');
        Route::delete('payments/{transaction}/refunds/{refund}', [InvoicePaymentController::class, 'undoRefund'])->whereNumber('refund')->name('payments.refunds.destroy');

        Route::get('adjustment-notes', [InvoiceAdjustmentNoteController::class, 'index'])->name('adjustment-notes.index');
        Route::post('adjustment-notes', [InvoiceAdjustmentNoteController::class, 'store'])->name('adjustment-notes.store');
        Route::put('adjustment-notes/{adjustment_note}', [InvoiceAdjustmentNoteController::class, 'update'])->name('adjustment-notes.update');
        Route::post('adjustment-notes/{adjustment_note}/void', [InvoiceAdjustmentNoteController::class, 'void'])->name('adjustment-notes.void');
        Route::post('adjustment-notes/{adjustment_note}/unvoid', [InvoiceAdjustmentNoteController::class, 'unvoid'])->name('adjustment-notes.unvoid');
        Route::delete('adjustment-notes/{adjustment_note}', [InvoiceAdjustmentNoteController::class, 'destroy'])->name('adjustment-notes.destroy');
    });
});
