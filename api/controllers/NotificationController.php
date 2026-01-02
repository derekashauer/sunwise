<?php
/**
 * Push Notification Controller
 */

class NotificationController
{
    /**
     * Subscribe to push notifications
     */
    public function subscribe(array $params, array $body, ?int $userId): array
    {
        $subscription = $body['subscription'] ?? null;

        if (!$subscription) {
            return ['status' => 400, 'data' => ['error' => 'Subscription data required']];
        }

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
