<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('settings', 'pages::settings.index')->name('settings.index');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');
    Route::livewire('settings/relationship', 'pages::settings.relationship')->name('relationship.edit');
    Route::livewire('settings/prompt-schedule', 'pages::settings.prompt-schedule')->name('prompt-schedule.edit');
    Route::livewire('settings/prompt-libraries', 'pages::settings.prompt-libraries')->name('prompt-libraries.edit');
    Route::livewire('settings/notifications', 'pages::settings.notifications')->name('notifications.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
