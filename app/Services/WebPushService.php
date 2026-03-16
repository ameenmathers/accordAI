<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private WebPush $webPush;

    public function __construct()
    {
        $publicKey  = config('services.vapid.public_key');
        $privateKey = config('services.vapid.private_key');

        if ($publicKey && $privateKey) {
            $this->webPush = new WebPush([
                'VAPID' => [
                    'subject'    => config('services.vapid.subject', 'mailto:hello@accordai.app'),
                    'publicKey'  => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);
        }
    }

    /**
     * Send a push notification to all subscribers of the given user IDs.
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        if (! isset($this->webPush)) {
            return; // VAPID keys not configured
        }

        $subscriptions = PushSubscription::whereIn('user_id', $userIds)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        foreach ($subscriptions as $sub) {
            $this->webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'p256dh' => $sub->p256dh_key,
                        'auth' => $sub->auth_key,
                    ],
                ]),
                $payload
            );
        }

        foreach ($this->webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getRequest()->getUri()->__toString())->delete();
            }
        }
    }
}
