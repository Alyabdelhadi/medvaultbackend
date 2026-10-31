<?php

use App\Http\Controllers\AiSummaryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\DgController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\LabResultsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
| The WordPress plugin's custom/v1 namespace, at the same paths: the API
| prefix is "wp-json/custom/v1" (bootstrap/app.php), and the site is served
| from /app, so the app's base URL keeps working unchanged:
|
|   https://medvaultapp.com/app/wp-json/custom/v1/...
|
| Methods are WordPress's: CREATABLE is POST, READABLE is GET, DELETABLE is
| DELETE. Anything else is a rest_no_route 404, as before.
*/

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('dg/register', [AuthController::class, 'dgRegister']);
Route::post('password/reset', [AuthController::class, 'passwordReset']);
Route::post('otp/send', [OtpController::class, 'send']);
Route::post('otp/verify', [OtpController::class, 'verify']);

Route::middleware('app.token')->group(function () {
    Route::get('profile', [ProfileController::class, 'show']);
    Route::post('profile/update', [ProfileController::class, 'update']);
    Route::delete('account/delete', [ProfileController::class, 'destroy']);
    Route::post('lab-token/refresh', [AuthController::class, 'refreshLabTokens']);
});

// Public directory and content.
Route::get('labs', [DirectoryController::class, 'index']);
Route::get('labs/{id}', [DirectoryController::class, 'show'])->whereNumber('id');
Route::get('sliders', [ContentController::class, 'sliders']);
Route::get('banners', [ContentController::class, 'banners']);
Route::get('result-categories', [ContentController::class, 'resultCategories']);
Route::get('about', [ContentController::class, 'about']);
Route::get('faq', [ContentController::class, 'faq']);
Route::get('phone', [ContentController::class, 'phone']);

// Lab results. The app never calls a lab; these do, on its behalf.
Route::middleware('app.token')->group(function () {
    Route::get('lab-results', [LabResultsController::class, 'index']);
    Route::get('lab-results/{requestNb}', [LabResultsController::class, 'show'])->where('requestNb', '[A-Za-z0-9_-]+');
    Route::get('dg/requests', [DgController::class, 'requests']);
    Route::post('ai-summary', [AiSummaryController::class, 'store']);
});
// Authorised inside: the OS browser opens it with no headers, so a signed
// link is the credential. A bearer token is accepted too.
Route::get('lab-file', [LabResultsController::class, 'file']);

// Devices and the in-app notification list.
Route::middleware('app.token')->group(function () {
    Route::post('device/register', [NotificationController::class, 'register']);
    Route::post('device/unregister', [NotificationController::class, 'unregister']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read', [NotificationController::class, 'read']);
    Route::post('notifications/delete', [NotificationController::class, 'delete']);
    Route::post('notifications/clear', [NotificationController::class, 'clear']);
});
