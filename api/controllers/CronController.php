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

        // Log the cron run
        $this->logCronRun('daily_reminders', $sent, $skipped, $errors);

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
     * Log a cron job run
     */
    private function logCronRun(string $jobName, int $processed, int $skipped, array $errors): void
    {
        try {
            // Create table if it doesn't exist
            db()->exec('
                CREATE TABLE IF NOT EXISTS cron_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_name TEXT NOT NULL,
                    ran_at TEXT DEFAULT (datetime("now")),
                    processed INTEGER DEFAULT 0,
                    skipped INTEGER DEFAULT 0,
                    errors TEXT,
                    success INTEGER DEFAULT 1
                )
            ');

            $stmt = db()->prepare('
                INSERT INTO cron_log (job_name, processed, skipped, errors, success)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $jobName,
                $processed,
                $skipped,
                !empty($errors) ? json_encode($errors) : null,
                empty($errors) ? 1 : 0
            ]);
        } catch (Exception $e) {
            error_log('Failed to log cron run: ' . $e->getMessage());
        }
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
     * Get cron job status
     * GET /cron/status
     * Requires authentication (for dashboard display)
     */
    public function status(array $params, array $body, ?int $userId): array
    {
        if (!$userId) {
            return ['status' => 401, 'data' => ['error' => 'Authentication required']];
        }

        // Create table if it doesn't exist (in case cron never ran)
        try {
            db()->exec('
                CREATE TABLE IF NOT EXISTS cron_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    job_name TEXT NOT NULL,
                    ran_at TEXT DEFAULT (datetime("now")),
                    processed INTEGER DEFAULT 0,
                    skipped INTEGER DEFAULT 0,
                    errors TEXT,
                    success INTEGER DEFAULT 1
                )
            ');
        } catch (Exception $e) {
            // Table might already exist
        }

        // Get last run for each job type
        $stmt = db()->query('
            SELECT job_name, ran_at, processed, skipped, errors, success
            FROM cron_log
            WHERE id IN (
                SELECT MAX(id) FROM cron_log GROUP BY job_name
            )
            ORDER BY ran_at DESC
        ');
        $lastRuns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get recent runs (last 24 hours)
        $stmt = db()->query('
            SELECT job_name, ran_at, processed, skipped, errors, success
            FROM cron_log
            WHERE ran_at > datetime("now", "-24 hours")
            ORDER BY ran_at DESC
            LIMIT 10
        ');
        $recentRuns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Determine status
        $status = 'not_configured';
        $statusMessage = 'Cron jobs have never run. Make sure the cron job is configured on the server.';
        $lastDailyReminder = null;

        foreach ($lastRuns as $run) {
            if ($run['job_name'] === 'daily_reminders') {
                $lastDailyReminder = $run;
                break;
            }
        }

        if ($lastDailyReminder) {
            $lastRunTime = strtotime($lastDailyReminder['ran_at']);
            $hoursSinceRun = (time() - $lastRunTime) / 3600;

            if ($hoursSinceRun > 48) {
                $status = 'error';
                $statusMessage = 'Daily reminder cron has not run in over 48 hours. Check server configuration.';
            } elseif ($hoursSinceRun > 25) {
                $status = 'warning';
                $statusMessage = 'Daily reminder cron has not run in over 25 hours. It should run daily.';
            } else {
                $status = 'ok';
                $statusMessage = 'Cron jobs are running normally.';
            }
        }

        return [
            'status' => $status,
            'status_message' => $statusMessage,
            'last_runs' => $lastRuns,
            'recent_runs' => $recentRuns,
            'last_daily_reminder' => $lastDailyReminder ? [
                'ran_at' => $lastDailyReminder['ran_at'],
                'emails_sent' => (int)$lastDailyReminder['processed'],
                'skipped' => (int)$lastDailyReminder['skipped'],
                'success' => (bool)$lastDailyReminder['success']
            ] : null
        ];
    }

    /**
     * Test endpoint - send a test email to a specific user
     * GET /cron/test-email?key=SECRET&email=user@example.com
     * Protected by cron key (for testing via URL)
     */
    public function testEmail(array $params, array $body, ?int $userId): array
    {
        // Allow either JWT auth OR cron key auth
        if (!$userId && !$this->validateCronKey($params)) {
            return ['status' => 401, 'data' => ['error' => 'Authentication required']];
        }

        // If using cron key, get user by email param
        if (!$userId) {
            $email = $_GET['email'] ?? null;
            if (!$email) {
                return ['status' => 400, 'data' => ['error' => 'Email parameter required when using key auth']];
            }
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) {
                return ['status' => 404, 'data' => ['error' => 'User not found']];
            }
            $userId = $user['id'];
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
