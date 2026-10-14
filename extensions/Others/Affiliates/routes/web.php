<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\Affiliates\Http\Controllers\AffiliateController;
use Paymenter\Extensions\Others\Affiliates\Livewire\Affiliates\Affiliate;

Route::group(['middleware' => ['web', 'auth']], function () {
    Route::get('/account/affiliate', Affiliate::class)->name('affiliate.index')->middleware('client.can:affiliates');
});

Route::group(['middleware' => ['api', 'api.admin'], 'prefix' => '/api/v1/admin'], function () {
    Route::apiResources([
        'affiliates' => AffiliateController::class,
    ]);
});
