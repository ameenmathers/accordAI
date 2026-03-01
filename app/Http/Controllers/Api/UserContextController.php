<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserContextNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provides read access to a user's stored behavioral context notes.
 * Useful for the Flutter app to display what AccordAI has learned
 * about a user across sessions.
 *
 * GET /api/context-notes          — all notes for the authenticated user
 * GET /api/context-notes/{type}   — notes filtered by context_type
 * DELETE /api/context-notes/{id}  — remove a specific note (user consent)
 */
class UserContextController extends Controller
{
    /**
     * Return all stored context notes for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $notes = UserContextNote::where('user_id', $request->user()->id)
            ->orderBy('context_type')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($note) => $this->formatNote($note));

        return response()->json($notes);
    }

    /**
     * Return notes filtered by context_type (e.g. "relationship").
     */
    public function byContextType(Request $request, string $contextType): JsonResponse
    {
        $notes = UserContextNote::where('user_id', $request->user()->id)
            ->where('context_type', $contextType)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($note) => $this->formatNote($note));

        return response()->json($notes);
    }

    /**
     * Delete a specific note — respecting user's right to remove stored context.
     */
    public function destroy(Request $request, UserContextNote $contextNote): JsonResponse
    {
        if ($contextNote->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $contextNote->delete();

        return response()->json(['message' => 'Context note removed.']);
    }

    private function formatNote(UserContextNote $note): array
    {
        return [
            'id' => $note->id,
            'context_type' => $note->context_type,
            'trait' => $note->trait,
            'source_chat_id' => $note->source_chat_id,
            'created_at' => $note->created_at,
        ];
    }
}
