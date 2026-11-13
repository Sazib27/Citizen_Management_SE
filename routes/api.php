<?php

use App\Http\Controllers\Api\FakeNidStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/nid/{nidNumber}', [FakeNidStatusController::class, 'show'])
    ->where('nidNumber', '\\d{10}')
    ->name('api.nid.status')
    // This endpoint is public, so it is throttled to stop it being
    // used to enumerate valid NIDs in the registry.
    ->middleware('throttle:nid-status');
