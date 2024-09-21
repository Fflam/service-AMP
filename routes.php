<?php

use Illuminate\Support\Facades\Route;
use App\Services\ServiceAMP\Service;

Route::any('/services/ServiceAMP/callback', [Service::class, 'callback'])->name('service.ServiceAMP.callback');