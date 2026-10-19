<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HomeImagesController;
use App\Http\Controllers\Admin\LabController;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login.submit');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [AuthController::class, 'home'])->middleware('can:manage-users')->name('home');

        // Administrators only.
        Route::middleware('can:admin')->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::get('labs', [LabController::class, 'index'])->name('labs.index');
            Route::get('labs/create', [LabController::class, 'create'])->name('labs.create');
            Route::post('labs', [LabController::class, 'store'])->name('labs.store');
            Route::get('labs/{lab}/edit', [LabController::class, 'edit'])->name('labs.edit');
            Route::put('labs/{lab}', [LabController::class, 'update'])->name('labs.update');
            Route::post('labs/{lab}/trash', [LabController::class, 'trash'])->name('labs.trash');
            Route::post('labs/{lab}/restore', [LabController::class, 'restore'])->name('labs.restore');

            Route::get('{kind}', [HomeImagesController::class, 'index'])->whereIn('kind', ['sliders', 'banners'])->name('images.index');
            Route::post('{kind}', [HomeImagesController::class, 'store'])->whereIn('kind', ['sliders', 'banners'])->name('images.store');
            Route::put('{kind}/{id}', [HomeImagesController::class, 'update'])->whereIn('kind', ['sliders', 'banners'])->whereNumber('id')->name('images.update');
            Route::delete('{kind}/{id}', [HomeImagesController::class, 'destroy'])->whereIn('kind', ['sliders', 'banners'])->whereNumber('id')->name('images.destroy');
            Route::post('{kind}/{id}/move/{direction}', [HomeImagesController::class, 'move'])->whereIn('kind', ['sliders', 'banners'])->whereNumber('id')->whereIn('direction', ['up', 'down'])->name('images.move');

            Route::get('result-categories', [ContentController::class, 'categories'])->name('categories.edit');
            Route::post('result-categories', [ContentController::class, 'saveCategories'])->name('categories.update');
            Route::get('pages/{key}', [ContentController::class, 'page'])->whereIn('key', ['about', 'faq'])->name('pages.edit');
            Route::put('pages/{key}', [ContentController::class, 'savePage'])->whereIn('key', ['about', 'faq'])->name('pages.update');

            Route::get('push', [NotificationsController::class, 'push'])->name('push.index');
            Route::post('push', [NotificationsController::class, 'send'])->name('push.send');
            Route::get('push/firebase', [NotificationsController::class, 'firebase'])->name('push.firebase');
            Route::post('push/firebase', [NotificationsController::class, 'saveFirebase'])->name('push.firebase.save');
            Route::get('result-alerts', [NotificationsController::class, 'alerts'])->name('alerts.show');
            Route::post('result-alerts', [NotificationsController::class, 'saveAlerts'])->name('alerts.save');
            Route::post('result-alerts/run', [NotificationsController::class, 'runPoll'])->name('alerts.run');
            Route::get('ai-summary', [NotificationsController::class, 'ai'])->name('ai.show');
            Route::post('ai-summary', [NotificationsController::class, 'saveAi'])->name('ai.save');
        });

        // Administrators and lab managers; managers are scoped to their lab.
        Route::middleware('can:manage-users')->whereIn('slug', ['patients', 'doctors', 'guarantors'])->group(function () {
            Route::get('{slug}', [UserController::class, 'index'])->name('users.index');
            Route::get('{slug}/new', [UserController::class, 'create'])->name('users.create');
            Route::post('{slug}', [UserController::class, 'store'])->name('users.store');
            Route::get('{slug}/export', [UserController::class, 'export'])->name('users.export');
            Route::get('{slug}/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
            Route::post('{slug}/{user}/access', [UserController::class, 'access'])->whereNumber('user')->name('users.access');
            Route::post('{slug}/{user}/password', [UserController::class, 'password'])->whereNumber('user')->name('users.password');
            Route::delete('{slug}/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
        });
    });
});
