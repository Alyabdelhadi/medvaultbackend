<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

/*
| WordPress core's own REST namespace, wp-json/wp/v2, for the single route
| the app calls in it: the photo upload. Anonymous, as the plugin made it;
| see MediaController for why, and for what replaces the protection.
*/

Route::post('media', [MediaController::class, 'store'])->middleware('throttle:uploads');
