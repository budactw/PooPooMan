<?php

use App\Http\Controllers\PoopRecordsController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook', [PoopRecordsController::class, 'webhook']);
