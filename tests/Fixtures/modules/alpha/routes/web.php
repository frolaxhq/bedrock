<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('alpha/ping', static fn (): string => 'alpha')->name('alpha.ping');
