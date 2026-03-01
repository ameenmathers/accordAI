<?php

use App\Http\Controllers\Api\AiMediationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserContextController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AccordAI REST API Routes
|--------------------------------------------------------------------------
| Consumed by the Flutter mobile app using Sanctum token-based auth.
| All protected routes require: Authorization: Bearer {token}
*/

// ── Authentication (no token required) ───────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

// ── Protected routes (Sanctum token required) ─────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth helpers
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // User search — for adding participants when creating a chat
    Route::get('users/search', [AuthController::class, 'searchUsers']);

    // ── Chats ─────────────────────────────────────────────────────────────
    Route::get('chats', [ChatController::class, 'index']);
    Route::post('chats', [ChatController::class, 'store']);
    Route::get('chats/{chat}', [ChatController::class, 'show']);

    // ── Messages ──────────────────────────────────────────────────────────
    // GET returns paginated history; POST sends message + triggers AI
    Route::get('chats/{chat}/messages', [MessageController::class, 'index']);
    Route::post('chats/{chat}/messages', [MessageController::class, 'store']);

    // ── Finalization (triggers Claude memory extraction) ─────────────────
    Route::post('chats/{chat}/finalize', [AiMediationController::class, 'finalize']);

    // ── User context notes (behavioral memory) ────────────────────────────
    Route::get('context-notes', [UserContextController::class, 'index']);
    Route::get('context-notes/{contextType}', [UserContextController::class, 'byContextType']);
    Route::delete('context-notes/{contextNote}', [UserContextController::class, 'destroy']);
});
