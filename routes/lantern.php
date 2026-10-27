<?php

use Illuminate\Support\Facades\Route;
use Lantern\Http\Controllers\ApiController;
use Lantern\Http\Controllers\PageController;

Route::get('/api/traces', [ApiController::class, 'traces'])->name('lantern.api.traces');
Route::get('/api/traces/{id}', [ApiController::class, 'trace'])->name('lantern.api.trace');
Route::get('/api/issues', [ApiController::class, 'issues'])->name('lantern.api.issues');
Route::get('/api/issues/{key}', [ApiController::class, 'issue'])->name('lantern.api.issue');

Route::get('/', [PageController::class, 'traces'])->name('lantern.traces');
Route::get('/traces/{id}', [PageController::class, 'trace'])->name('lantern.trace');
Route::get('/issues', [PageController::class, 'issues'])->name('lantern.issues');
Route::get('/issues/{key}', [PageController::class, 'issue'])->name('lantern.issue');
