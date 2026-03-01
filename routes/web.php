<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// ── AccordAI Chat Routes ──────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('chats')->name('chats.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::post('/', [ChatController::class, 'store'])->name('store');
    Route::get('/{chat}', [ChatController::class, 'show'])->name('show');
    Route::post('/{chat}/messages', [ChatController::class, 'sendMessage'])->name('messages.store');
    Route::post('/{chat}/finalize', [ChatController::class, 'finalize'])->name('finalize');
});

// ── Invitation Routes ─────────────────────────────────────────────────────
//
// Public (no auth):
//   /invitations/{token}                   → landing page (show invite details)
//   /invitations/{token}/login-redirect    → stores intended URL, sends to /login
//   /invitations/{token}/register-redirect → stores token in session, sends to /register
//
// Protected (auth required):
//   /invitations/{token}/accept            → does the actual acceptance
//
Route::prefix('invitations')->name('invitations.')->group(function () {

    // Public landing page — seen before auth
    Route::get('/{token}', [InvitationController::class, 'show'])
        ->name('show');

    // Redirect helpers that preserve the token across the auth flow
    Route::get('/{token}/login-redirect', [InvitationController::class, 'redirectToLogin'])
        ->name('login-redirect');
    Route::get('/{token}/register-redirect', [InvitationController::class, 'redirectToRegister'])
        ->name('register-redirect');

    // Protected — auth middleware redirects to login, then returns here
    Route::middleware(['auth'])->group(function () {
        Route::get('/{token}/accept', [InvitationController::class, 'accept'])
            ->name('accept');
    });
});

require __DIR__.'/settings.php';
