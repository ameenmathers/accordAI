<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages chat creation, listing, and participant management for the REST API.
 * Used by the Flutter mobile app.
 *
 * POST /api/chats              — create a new chat
 * GET  /api/chats              — list chats for authenticated user
 * GET  /api/chats/{id}         — get a single chat with participants
 */
class ChatController extends Controller
{
    /**
     * List all chats the authenticated user participates in.
     */
    public function index(Request $request): JsonResponse
    {
        $chats = $request->user()
            ->chats()
            ->with(['creator:id,name', 'participants:id,name'])
            ->withCount('messages')
            ->latest()
            ->get()
            ->map(fn ($chat) => $this->formatChat($chat));

        return response()->json($chats);
    }

    /**
     * Create a new chat and add participants (including the creator).
     * Max 3 participants enforced here.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'context_type' => ['required', 'string', 'in:relationship,business,family,financial,legal,general'],
            'title' => ['nullable', 'string', 'max:255'],
            // Additional participant IDs (the creator is added automatically)
            'participant_ids' => ['nullable', 'array', 'max:2'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // Create the chat
        $chat = Chat::create([
            'context_type' => $validated['context_type'],
            'title' => $validated['title'] ?? null,
            'created_by' => $request->user()->id,
            'status' => 'active',
        ]);

        // Always add the creator as a participant
        $chat->participants()->attach($request->user()->id);

        // Add additional participants (de-duplicate, skip creator if included)
        $additionalIds = collect($validated['participant_ids'] ?? [])
            ->reject(fn ($id) => $id === $request->user()->id)
            ->unique()
            ->take(2); // creator + 2 more = max 3

        foreach ($additionalIds as $userId) {
            if ($chat->canAddParticipant()) {
                $chat->participants()->attach($userId);
            }
        }

        $chat->load(['creator:id,name', 'participants:id,name']);

        return response()->json($this->formatChat($chat), 201);
    }

    /**
     * Return a single chat with its participants.
     */
    public function show(Request $request, Chat $chat): JsonResponse
    {
        // Ensure the user is a participant in this chat
        $this->authorizeParticipant($request->user(), $chat);

        $chat->load(['creator:id,name', 'participants:id,name']);

        return response()->json($this->formatChat($chat));
    }

    /**
     * Verify the requesting user is a participant in the chat.
     */
    private function authorizeParticipant(User $user, Chat $chat): void
    {
        if (! $chat->participants()->where('user_id', $user->id)->exists()) {
            abort(403, 'You are not a participant in this chat.');
        }
    }

    private function formatChat(Chat $chat): array
    {
        return [
            'id' => $chat->id,
            'context_type' => $chat->context_type,
            'title' => $chat->title,
            'status' => $chat->status,
            'created_by' => $chat->creator?->only(['id', 'name']),
            'participants' => $chat->participants->map->only(['id', 'name'])->values(),
            'messages_count' => $chat->messages_count ?? 0,
            'created_at' => $chat->created_at,
            'updated_at' => $chat->updated_at,
        ];
    }
}
