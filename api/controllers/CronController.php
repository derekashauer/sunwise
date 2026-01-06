<?php
/**
 * Cron Controller
 * Handles scheduled tasks like daily email reminders
 */

class CronController
{
    // Secret key for cron authentication (set in .env as CRON_SECRET)
    private function validateCronKey(array $params): bool
    {
        $cronSecret = getenv('CRON_SECRET') ?: '';

        // If no secret is configured, allow access (for initial setup)
        if (empty($cronSecret)) {
            return true;
        }

        $providedKey = $params['key'] ?? ($_GET['key'] ?? '');
        return hash_equals($cronSecret, $providedKey);
    }

    /**
     * Send daily reminder emails to users who have enabled them
     * GET /cron/daily-reminders?key=SECRET
     *
     * This should be called by a cron job, e.g.:
     * 0 7 * * * curl -s "https://dereka328.sg-host.com/api/cron/daily-reminders?key=YOUR_SECRET"
     */
    public function dailyReminders(array $params, array $body, ?int $userId): array
    {
        // Validate cron key
        if (!$this->validateCronKey($params)) {
            return ['status' => 403, 'data' => ['error' => 'Invalid cron key']];
        }

        $today = date('Y-m-d');
        $currentTime = date('H:i');
        $sent = 0;
        $skipped = 0;
        $errors = [];

        // Get users who have email digest enabled
        // We check if their preferred time is within 30 minutes of now
        // and they haven't received a digest today
        $stmt = db()->prepare('
            SELECT u.id, u.email, u.email_digest_time, u.last_digest_sent
            FROM users u
            WHERE u.email_digest_enabled = 1
              AND (u.last_digest_sent IS NULL OR u.last_digest_sent < ?)
        ');
        $stmt->execute([$today]);
        $users = $stmt->fetchAll();

        $emailService = new EmailService();

        foreach ($users as $user) {
            // Check if it's time to send (within 30 minute window)
            $preferredTime = $user['email_digest_time'] ?? '08:00';

            if (!$this->isWithinTimeWindow($currentTime, $preferredTime, 30)) {
                $skipped++;
                continue;
            }

            // Get today's tasks for this user (excluding graveyard plants)
            $tasks = $this->getUserTasks($user['id'], $today);

            // Skip if no tasks
            if (empty($tasks)) {
                // Still mark as sent so we don't keep checking
                $this->markDigestSent($user['id'], $today);
                $skipped++;
                continue;
            }

            // Extract username from email for greeting
            $userName = ucfirst(explode('@', $user['email'])[0]);

            // Send the email
            try {
                $result = $emailService->sendDailyReminder($user['email'], $tasks, $userName);

                if ($result) {
                    $this->markDigestSent($user['id'], $today);
                    $sent++;
                } else {
                    $errors[] = "Failed to send to {$user['email']}";
                }
            } catch (Exception $e) {
                $errors[] = "Error sending to {$user['email']}: " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'date' => $today,
            'time' => $currentTime,
            'emails_sent' => $sent,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Check if current time is within window of target time
     */
    private function isWithinTimeWindow(string $current, string $target, int $windowMinutes): bool
    {
        $currentMinutes = $this->timeToMinutes($current);
        $targetMinutes = $this->timeToMinutes($target);

        $diff = abs($currentMinutes - $targetMinutes);

        // Handle midnight wraparound
        if ($diff > 12 * 60) {
            $diff = 24 * 60 - $diff;
        }

        return $diff <= $windowMinutes;
    }

    /**
     * Convert HH:MM to minutes since midnight
     */
    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int)$parts[0] * 60) + (int)($parts[1] ?? 0);
    }

    /**
     * Get today's pending tasks for a user
     */
    private function getUserTasks(int $userId, string $today): array
    {
        $stmt = db()->prepare('
            SELECT t.id, t.task_type, t.due_date, t.priority,
                   p.name as plant_name, p.location as plant_location
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE p.user_id = ?
              AND t.due_date <= ?
              AND t.completed_at IS NULL
              AND t.skipped_at IS NULL
              AND p.archived_at IS NULL
            ORDER BY
                CASE t.priority
                    WHEN "urgent" THEN 1
                    WHEN "high" THEN 2
                    WHEN "normal" THEN 3
                    WHEN "low" THEN 4
                    ELSE 5
                END,
                t.due_date ASC
        ');
        $stmt->execute([$userId, $today]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark that we sent a digest to this user today
     */
    private function markDigestSent(int $userId, string $date): void
    {
        $stmt = db()->prepare('UPDATE users SET last_digest_sent = ? WHERE id = ?');
        $stmt->execute([$date, $userId]);
    }

    /**
     * Test endpoint - send a test email to current user
     * GET /cron/test-email
     * Requires authentication
     */
    public function testEmail(array $params, array $body, ?int $userId): array
    {
        if (!$userId) {
            return ['status' => 401, 'data' => ['error' => 'Authentication required']];
        }

        // Get user email
        $stmt = db()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['status' => 404, 'data' => ['error' => 'User not found']];
        }

        // Get today's tasks
        $today = date('Y-m-d');
        $tasks = $this->getUserTasks($userId, $today);

        // If no tasks, create a sample task for testing
        if (empty($tasks)) {
            $tasks = [
                [
                    'id' => 0,
                    'task_type' => 'water',
                    'due_date' => $today,
                    'priority' => 'high',
                    'plant_name' => 'Sample Plant',
                    'plant_location' => 'Living Room'
                ],
                [
                    'id' => 0,
                    'task_type' => 'fertilize',
                    'due_date' => $today,
                    'priority' => 'normal',
                    'plant_name' => 'Another Plant',
                    'plant_location' => 'Bedroom'
                ]
            ];
        }

        $userName = ucfirst(explode('@', $user['email'])[0]);
        $emailService = new EmailService();
        $result = $emailService->sendDailyReminder($user['email'], $tasks, $userName);

        return [
            'success' => $result,
            'email' => $user['email'],
            'task_count' => count($tasks),
            'message' => $result ? 'Test email sent!' : 'Failed to send email'
        ];
    }
}
