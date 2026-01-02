<?php
/**
 * Web Push Notification Service
 */

class PushService
{
    /**
     * Send push notification to user
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): bool
    {
        $stmt = db()->prepare('SELECT push_subscription FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !$user['push_subscription']) {
            return false;
        }

        $subscription = json_decode($user['push_subscription'], true);
        if (!$subscription) {
            return false;
        }

        return $this->send($subscription, $title, $body, $data);
    }

    /**
     * Send push notification
     */
    public function send(array $subscription, string $title, string $body, array $data = []): bool
    {
        if (!VAPID_PUBLIC_KEY || !VAPID_PRIVATE_KEY) {
            error_log('VAPID keys not configured');
            return false;
        }

        $endpoint = $subscription['endpoint'];
        $p256dh = $subscription['keys']['p256dh'];
        $auth = $subscription['keys']['auth'];

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/icons/icon-192.png',
            'badge' => '/icons/badge-72.png',
            'data' => $data
        ]);

        // Note: For production, use a proper web-push library like minishlink/web-push
        // This is a simplified implementation
        try {
            $headers = $this->generateVapidHeaders($endpoint);

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array_merge([
                    'Content-Type: application/octet-stream',
                    'Content-Length: ' . strlen($payload),
                    'TTL: 86400'
                ], $headers),
                CURLOPT_RETURNTRANSFER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;
        } catch (Exception $e) {
            error_log('Push notification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate VAPID authorization headers
     * Note: Simplified - use web-push library for production
     */
    private function generateVapidHeaders(string $endpoint): array
    {
        // In production, implement proper VAPID signing
        // For now, return basic headers
        return [
            'Authorization: vapid t=' . VAPID_PUBLIC_KEY
        ];
    }

    /**
     * Send notification to all users with due tasks
     */
    public function notifyDueTasks(): int
    {
        $today = date('Y-m-d');

        $stmt = db()->prepare('
            SELECT DISTINCT u.id, u.push_subscription,
                   COUNT(t.id) as task_count
            FROM users u
            JOIN plants p ON u.id = p.user_id
            JOIN tasks t ON p.id = t.plant_id
            WHERE t.due_date = ?
              AND t.completed_at IS NULL
              AND t.skipped_at IS NULL
              AND u.push_subscription IS NOT NULL
            GROUP BY u.id
        ');
        $stmt->execute([$today]);
        $users = $stmt->fetchAll();

        $sent = 0;
        foreach ($users as $user) {
            $count = $user['task_count'];
            $result = $this->sendToUser(
                $user['id'],
                'Plant Care Reminder',
                "You have $count plant care task" . ($count > 1 ? 's' : '') . " today!",
                ['url' => '/']
            );
            if ($result) {
                $sent++;
            }
        }

        return $sent;
    }
}
