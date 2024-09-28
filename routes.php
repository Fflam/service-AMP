<?php

use Illuminate\Support\Facades\Route;
use App\Services\ServiceAMP\Service;

Route::any('/services/serviceamp/callback', [Service::class, 'callback'])->name('service.serviceamp.callback');