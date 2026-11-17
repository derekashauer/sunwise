<?php
/**
 * Push Notification Controller
 */

class NotificationController
{
    /**
     * The public VAPID key the browser needs to subscribe.
     * GET /notifications/vapid-public-key
     */
    public function vapidPublicKey(array $params, array $body, ?int $userId): array
    {
        return ['public_key' => PushService::isConfigured() ? VAPID_PUBLIC_KEY : ''];
    }

    /**
     * Subscribe to push notifications
     */
    public function subscribe(array $params, array $body, ?int $userId): array
    {
        $subscription = $body['subscription'] ?? null;

        if (!is_array($subscription) || empty($subscription['endpoint'])
            || !preg_match('#^https://#', (string) $subscription['endpoint'])) {
            return ['status' => 400, 'data' => ['error' => 'Subscription data required']];
        }

        // Keep only the fields a push subscription has
        $subscription = [
            'endpoint' => (string) $subscription['endpoint'],
            'keys' => [
                'p256dh' => (string) ($subscription['keys']['p256dh'] ?? ''),
                'auth' => (string) ($subscription['keys']['auth'] ?? ''),
            ],
        ];

        // Store subscription
        $stmt = db()->prepare('UPDATE users SET push_subscription = ? WHERE id = ?');
        $stmt->execute([json_encode($subscription), $userId]);

        return ['message' => 'Subscribed to notifications'];
    }

    /**
     * Unsubscribe from push notifications
     */
    public function unsubscribe(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('UPDATE users SET push_subscription = NULL WHERE id = ?');
        $stmt->execute([$userId]);

        return ['message' => 'Unsubscribed from notifications'];
    }
}
