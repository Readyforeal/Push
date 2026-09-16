<?php

use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\RoundPhotoController;
use App\Http\Controllers\SharedMomentPhotoController;
use App\Http\Controllers\UserBackgroundController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('history', 'pages::history')->name('history');
    Route::livewire('library', 'pages::library')->name('library');
    Route::livewire('missions', 'pages::missions')->name('missions');
    Route::livewire('moments', 'pages::moments')->name('moments');
    Route::livewire('invitations/{token}', 'pages::invitations.accept')->name('invitations.accept');
    Route::get('round-photos/{roundPhoto}', RoundPhotoController::class)->name('round-photos.show');
    Route::get('moment-photos/{sharedMomentPhoto}', SharedMomentPhotoController::class)->name('moment-photos.show');
    Route::get('background', UserBackgroundController::class)->name('background.show');

    Route::prefix('push')->name('push.')->group(function () {
        Route::post('subscriptions', [PushNotificationController::class, 'store'])->name('subscriptions.store');
        Route::delete('subscriptions', [PushNotificationController::class, 'destroy'])->name('subscriptions.destroy');
        Route::post('send', [PushNotificationController::class, 'send'])
            ->middleware('throttle:10,1')
            ->name('send');
    });
});

require __DIR__.'/settings.php';
