<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /**
     * Store or update a push subscription for the current user.
     * POST /push/subscribe
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url'],
            'p256dh_key' => ['required', 'string'],
            'auth_key' => ['required', 'string'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $request->user()->id,
                'p256dh_key' => $validated['p256dh_key'],
                'auth_key' => $validated['auth_key'],
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Remove a push subscription.
     * POST /push/unsubscribe
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        PushSubscription::where('endpoint', $validated['endpoint'])->delete();

        return response()->json(['ok' => true]);
    }
}
