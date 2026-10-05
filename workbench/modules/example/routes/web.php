<?php

declare(strict_types=1);

use Frolax\Bedrock\Facades\Frontend;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Example\Http\Controllers\ExampleController;

Route::get('/example', ExampleController::class)->name('example.index');
Route::get('/example/history', static fn () => Inertia::render(Frontend::page('example', 'History')))->name('example.history');
Route::get('/example/health', static fn () => response()->json(['module' => 'example']))->name('example.health');
