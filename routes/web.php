<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\InvitationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function (Request $request) {
    $pendingInvitations = $request->user()
        ->pendingInvitations()
        ->with(['chat:id,context_type,title,created_by', 'chat.creator:id,name'])
        ->get()
        ->map(fn ($inv) => [
            'id' => $inv->id,
            'chat_title' => $inv->chat->title ?? ucfirst($inv->chat->context_type).' Discussion',
            'context_type' => $inv->chat->context_type,
            'invited_by' => $inv->chat->creator->name,
        ]);

    return Inertia::render('Dashboard', [
        'pendingInvitations' => $pendingInvitations,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

// ── User search (web, returns JSON) ──────────────────────────────────────────
Route::middleware(['auth'])->get('/users/search', function (Request $request) {
    $q = $request->validate(['q' => ['required', 'string', 'min:1']])['q'];

    $users = \App\Models\User::where('id', '!=', $request->user()->id)
        ->where(function ($query) use ($q) {
            $query->where('username', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%");
        })
        ->select(['id', 'name', 'username'])
        ->limit(8)
        ->get();

    return response()->json($users);
})->name('users.search');

// ── AccordAI Chat Routes ──────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('chats')->name('chats.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::post('/', [ChatController::class, 'store'])->name('store');
    Route::get('/{chat}', [ChatController::class, 'show'])->name('show');
    Route::post('/{chat}/messages', [ChatController::class, 'sendMessage'])->name('messages.store');
    Route::get('/{chat}/messages', [ChatController::class, 'pollMessages'])->name('messages.poll');
    Route::post('/{chat}/typing', [ChatController::class, 'recordTyping'])->name('typing.record');
    Route::get('/{chat}/typing', [ChatController::class, 'getTyping'])->name('typing.get');
    Route::post('/{chat}/finalize', [ChatController::class, 'finalize'])->name('finalize');
    Route::post('/{chat}/invite-link', [ChatController::class, 'generateInviteLink'])->name('invite-link');
});

// ── Invitation Routes ─────────────────────────────────────────────────────
Route::prefix('invitations')->name('invitations.')->group(function () {

    // Public shareable-link landing page
    Route::get('/{token}', [InvitationController::class, 'show'])
        ->name('show');

    Route::get('/{token}/login-redirect', [InvitationController::class, 'redirectToLogin'])
        ->name('login-redirect');

    Route::get('/{token}/register-redirect', [InvitationController::class, 'redirectToRegister'])
        ->name('register-redirect');

    Route::middleware(['auth'])->group(function () {
        // Shareable link acceptance (GET so redirect from landing page works)
        Route::get('/{token}/accept', [InvitationController::class, 'accept'])
            ->name('accept');

        // Dashboard accept/decline (POST, uses invitation ID)
        Route::post('/{invitation}/accept', [InvitationController::class, 'acceptById'])
            ->name('accept-by-id');
        Route::post('/{invitation}/decline', [InvitationController::class, 'declineById'])
            ->name('decline-by-id');
    });
});

require __DIR__.'/settings.php';
