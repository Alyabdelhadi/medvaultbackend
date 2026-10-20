<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\HomeImagesController;
use App\Http\Controllers\Admin\LabController;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
| Every route below names its page and action, `perm:<page>,<action>`, from
| App\Support\AdminModules. Administrators pass every check; other accounts
| get what their group grants, and a lab's accounts never a site-wide page.
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login')->name('login.submit');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [AuthController::class, 'home'])->name('home');

        Route::get('dashboard', DashboardController::class)->middleware('perm:dashboard')->name('dashboard');

        Route::get('labs', [LabController::class, 'index'])->middleware('perm:labs')->name('labs.index');
        Route::get('labs/create', [LabController::class, 'create'])->middleware('perm:labs,create')->name('labs.create');
        Route::post('labs', [LabController::class, 'store'])->middleware('perm:labs,create')->name('labs.store');
        Route::get('labs/{lab}/edit', [LabController::class, 'edit'])->middleware('perm:labs')->name('labs.edit');
        Route::put('labs/{lab}', [LabController::class, 'update'])->middleware('perm:labs,edit')->name('labs.update');
        Route::post('labs/{lab}/trash', [LabController::class, 'trash'])->middleware('perm:labs,delete')->name('labs.trash');
        Route::post('labs/{lab}/restore', [LabController::class, 'restore'])->middleware('perm:labs,delete')->name('labs.restore');

        // sliders | banners, each its own page.
        Route::whereIn('kind', ['sliders', 'banners'])->group(function () {
            Route::get('{kind}', [HomeImagesController::class, 'index'])->middleware('perm:kind')->name('images.index');
            Route::post('{kind}', [HomeImagesController::class, 'store'])->middleware('perm:kind,create')->name('images.store');
            Route::put('{kind}/{id}', [HomeImagesController::class, 'update'])->whereNumber('id')->middleware('perm:kind,edit')->name('images.update');
            Route::delete('{kind}/{id}', [HomeImagesController::class, 'destroy'])->whereNumber('id')->middleware('perm:kind,delete')->name('images.destroy');
            Route::post('{kind}/{id}/move/{direction}', [HomeImagesController::class, 'move'])->whereNumber('id')->whereIn('direction', ['up', 'down'])->middleware('perm:kind,edit')->name('images.move');
        });

        Route::get('result-categories', [ContentController::class, 'categories'])->middleware('perm:categories')->name('categories.edit');
        Route::post('result-categories', [ContentController::class, 'saveCategories'])->middleware('perm:categories,edit')->name('categories.update');
        Route::get('pages/{key}', [ContentController::class, 'page'])->whereIn('key', ['about', 'faq'])->middleware('perm:pages')->name('pages.edit');
        Route::put('pages/{key}', [ContentController::class, 'savePage'])->whereIn('key', ['about', 'faq'])->middleware('perm:pages,edit')->name('pages.update');

        Route::get('push', [NotificationsController::class, 'push'])->middleware('perm:push')->name('push.index');
        Route::post('push', [NotificationsController::class, 'send'])->middleware('perm:push,create')->name('push.send');
        Route::get('push/firebase', [NotificationsController::class, 'firebase'])->middleware('perm:firebase')->name('push.firebase');
        Route::post('push/firebase', [NotificationsController::class, 'saveFirebase'])->middleware('perm:firebase,edit')->name('push.firebase.save');
        Route::get('result-alerts', [NotificationsController::class, 'alerts'])->middleware('perm:alerts')->name('alerts.show');
        Route::post('result-alerts', [NotificationsController::class, 'saveAlerts'])->middleware('perm:alerts,edit')->name('alerts.save');
        Route::post('result-alerts/run', [NotificationsController::class, 'runPoll'])->middleware('perm:alerts,edit')->name('alerts.run');
        Route::get('ai-summary', [NotificationsController::class, 'ai'])->middleware('perm:ai')->name('ai.show');
        Route::post('ai-summary', [NotificationsController::class, 'saveAi'])->middleware('perm:ai,edit')->name('ai.save');

        Route::get('accounting', [AccountingController::class, 'index'])->middleware('perm:accounting')->name('accounting.index');
        Route::get('accounting/labs/{lab}', [AccountingController::class, 'show'])->middleware('perm:accounting')->name('accounting.show');
        Route::get('accounting/labs/{lab}/plan', [AccountingController::class, 'editPlan'])->middleware('perm:accounting,edit')->name('accounting.plan');
        Route::put('accounting/labs/{lab}/plan', [AccountingController::class, 'savePlan'])->middleware('perm:accounting,edit')->name('accounting.plan.save');
        Route::get('accounting/labs/{lab}/payments/new', [AccountingController::class, 'newPayment'])->middleware('perm:accounting,create')->name('accounting.payment');
        Route::post('accounting/labs/{lab}/payments', [AccountingController::class, 'storePayment'])->middleware('perm:accounting,create')->name('accounting.payment.store');
        Route::delete('accounting/payments/{payment}', [AccountingController::class, 'destroyPayment'])->middleware('perm:accounting,delete')->name('accounting.payment.destroy');

        Route::get('accounts', [StaffController::class, 'index'])->middleware('perm:staff')->name('staff.index');
        Route::get('accounts/new', [StaffController::class, 'create'])->middleware('perm:staff,create')->name('staff.create');
        Route::post('accounts', [StaffController::class, 'store'])->middleware('perm:staff,create')->name('staff.store');
        Route::get('accounts/{user}/edit', [StaffController::class, 'edit'])->whereNumber('user')->middleware('perm:staff,edit')->name('staff.edit');
        Route::put('accounts/{user}', [StaffController::class, 'update'])->whereNumber('user')->middleware('perm:staff,edit')->name('staff.update');
        Route::delete('accounts/{user}', [StaffController::class, 'destroy'])->whereNumber('user')->middleware('perm:staff,delete')->name('staff.destroy');

        Route::get('groups', [GroupController::class, 'index'])->middleware('perm:groups')->name('groups.index');
        Route::get('groups/new', [GroupController::class, 'create'])->middleware('perm:groups,create')->name('groups.create');
        Route::post('groups', [GroupController::class, 'store'])->middleware('perm:groups,create')->name('groups.store');
        Route::get('groups/{group}/edit', [GroupController::class, 'edit'])->middleware('perm:groups,edit')->name('groups.edit');
        Route::put('groups/{group}', [GroupController::class, 'update'])->middleware('perm:groups,edit')->name('groups.update');
        Route::delete('groups/{group}', [GroupController::class, 'destroy'])->middleware('perm:groups,delete')->name('groups.destroy');

        // Patients, Doctors and Guarantors; a lab's accounts see their lab only.
        Route::whereIn('slug', ['patients', 'doctors', 'guarantors'])->group(function () {
            Route::get('{slug}', [UserController::class, 'index'])->middleware('perm:slug')->name('users.index');
            Route::get('{slug}/new', [UserController::class, 'create'])->middleware('perm:slug,create')->name('users.create');
            Route::post('{slug}', [UserController::class, 'store'])->middleware('perm:slug,create')->name('users.store');
            Route::get('{slug}/export', [UserController::class, 'export'])->middleware('perm:slug')->name('users.export');
            Route::get('{slug}/{user}', [UserController::class, 'show'])->whereNumber('user')->middleware('perm:slug')->name('users.show');
            Route::post('{slug}/{user}/password', [UserController::class, 'password'])->whereNumber('user')->middleware('perm:slug,edit')->name('users.password');
            Route::delete('{slug}/{user}', [UserController::class, 'destroy'])->whereNumber('user')->middleware('perm:slug,delete')->name('users.destroy');
        });
    });
});
