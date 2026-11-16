<?php
/**
 * Cron Controller
 * Handles scheduled tasks like daily email reminders
 */

class CronController
{
    /**
     * Cron calls must carry CRON_SECRET, in an X-Cron-Key header (preferred,
     * stays out of server logs) or a ?key= query parameter. With no secret
     * configured, cron endpoints are closed.
     */
    private function validateCronKey(array $params): bool
    {
        $cronSecret = getenv('CRON_SECRET') ?: '';
        if ($cronSecret === '') {
            error_log('Cron request refused: CRON_SECRET is not set');
            return false;
        }

        $providedKey = $_SERVER['HTTP_X_CRON_KEY'] ?? ($params['key'] ?? ($_GET['key'] ?? ''));
        return is_string($providedKey) && hash_equals($cronSecret, $providedKey);
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
        if (!$this->validateCronKey($params)) {
            return ['status' => 403, 'data' => ['error' => 'Invalid cron key']];
        }

        $sent = 0;
        $pushed = 0;
        $skipped = 0;
        $errors = [];

        // Everyone with email or push turned on. Each person's "today" and
        // reminder time are worked out in their own time zone.
        $users = db()->query('
            SELECT id, email, timezone, email_digest_enabled, email_digest_time,
                   last_digest_sent, push_subscription, last_push_sent
            FROM users
            WHERE email_digest_enabled = 1 OR push_subscription IS NOT NULL
        ')->fetchAll();

        $emailService = new EmailService();
        $pushService = new PushService();
        $scheduler = new CareScheduler();

        foreach ($users as $user) {
            Clock::useTimezone($user['timezone'] ?? null);
            $today = Clock::today();
            $preferredTime = $user['email_digest_time'] ?: '08:00';

            if (!$this->isWithinTimeWindow(date('H:i'), $preferredTime, 30)) {
                $skipped++;
                continue;
            }

            $wantsEmail = (int) $user['email_digest_enabled'] === 1 && ($user['last_digest_sent'] ?? '') < $today;
            $wantsPush = !empty($user['push_subscription']) && ($user['last_push_sent'] ?? '') < $today;
            if (!$wantsEmail && !$wantsPush) {
                $skipped++;
                continue;
            }

            // Same list as the Today screen, after the same daily cleanup
            $scheduler->reconcile((int) $user['id']);
            $tasks = $scheduler->tasksForToday((int) $user['id'], false);

            if ($wantsPush) {
                $this->markSent((int) $user['id'], 'last_push_sent', $today);
                if (!empty($tasks)) {
                    try {
                        if ($pushService->sendToUser((int) $user['id'])) {
                            $pushed++;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Push failed for user {$user['id']}: " . $e->getMessage();
                    }
                }
            }

            if ($wantsEmail) {
                if (empty($tasks)) {
                    $this->markSent((int) $user['id'], 'last_digest_sent', $today);
                    $skipped++;
                    continue;
                }

                $userName = ucfirst(explode('@', $user['email'])[0]);
                try {
                    if ($emailService->sendDailyReminder($user['email'], $tasks, $userName)) {
                        $this->markSent((int) $user['id'], 'last_digest_sent', $today);
                        $sent++;
                    } else {
                        $errors[] = "Failed to send email to user {$user['id']}";
                    }
                } catch (Exception $e) {
                    $errors[] = "Error sending email to user {$user['id']}: " . $e->getMessage();
                }
            }
        }

        Clock::useTimezone(null);
        $this->logCronRun('daily_reminders', $sent + $pushed, $skipped, $errors);

        return [
            'success' => true,
            'emails_sent' => $sent,
            'pushes_sent' => $pushed,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Record that today's email or push went out.
     */
    private function markSent(int $userId, string $column, string $date): void
    {
        if (!in_array($column, ['last_digest_sent', 'last_push_sent'], true)) {
            return;
        }
        $stmt = db()->prepare("UPDATE users SET {$column} = ? WHERE id = ?");
        $stmt->execute([$date, $userId]);
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
     * Evaluate care plans and regenerate stale or problematic ones
     * GET /cron/evaluate-plans?key=SECRET
     *
     * Should be called weekly, e.g.:
     * 0 3 * * 0 curl -s "https://dereka328.sg-host.com/api/cron/evaluate-plans?key=YOUR_SECRET"
     */
    public function evaluatePlans(array $params, array $body, ?int $userId): array
    {
        if (!$this->validateCronKey($params)) {
            return ['status' => 403, 'data' => ['error' => 'Invalid cron key']];
        }

        $regenerated = 0;
        $skipped = 0;
        $errors = [];

        // Find plants that need care plan evaluation:
        // 1. Plan expired (valid_until passed)
        // 2. Plan is old (generated over 60 days ago)
        // 3. No pending tasks (all completed/skipped, plan may be "half baked")
        // 4. Season changed since plan was generated
        $today = Clock::today();
        $currentSeason = Clock::season($today);

        // Evaluate plants WITH active care plans
        $stmt = db()->query("
            SELECT cp.id as care_plan_id, cp.plant_id, cp.season, cp.valid_until, cp.generated_at,
                   p.user_id, p.name as plant_name,
                   (SELECT COUNT(*) FROM tasks t
                    WHERE t.plant_id = cp.plant_id AND t.completed_at IS NULL AND t.skipped_at IS NULL
                   ) as pending_task_count,
                   (SELECT COUNT(*) FROM tasks t
                    WHERE t.plant_id = cp.plant_id AND t.task_type = 'check' AND t.completed_at IS NULL AND t.skipped_at IS NULL
                   ) as pending_check_count
            FROM care_plans cp
            JOIN plants p ON cp.plant_id = p.id
            WHERE cp.is_active = 1
              AND p.archived_at IS NULL
        ");
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also find plants with NO care plan at all
        $stmt2 = db()->query("
            SELECT p.id as plant_id, p.user_id, p.name as plant_name
            FROM plants p
            WHERE p.archived_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM care_plans cp WHERE cp.plant_id = p.id AND cp.is_active = 1)
        ");
        $planlessPlants = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $carePlanController = new CarePlanController();

        // Generate plans for plants that have none
        foreach ($planlessPlants as $plant) {
            Clock::useUserTimezone((int) $plant['user_id']);
            try {
                $carePlanController->generateCarePlan($plant['plant_id'], $plant['user_id']);
                $regenerated++;
                error_log("Cron: Generated care plan for plant {$plant['plant_name']} (ID: {$plant['plant_id']}). Reason: No care plan existed");
            } catch (Exception $e) {
                $errors[] = "Failed to generate plan for plant {$plant['plant_id']}: " . $e->getMessage();
            }
        }

        $disabledByUser = [];
        foreach ($plans as $plan) {
            $shouldRegenerate = false;
            $reason = '';
            $ownerId = (int) $plan['user_id'];
            Clock::useUserTimezone($ownerId);
            $today = Clock::today();
            $currentSeason = Clock::season($today);
            $disabledByUser[$ownerId] ??= CareScheduler::disabledTypes($ownerId);
            $checksOff = in_array('check', $disabledByUser[$ownerId], true);

            // 1. Plan expired
            if ($plan['valid_until'] && $plan['valid_until'] < $today) {
                $shouldRegenerate = true;
                $reason = 'Plan expired';
            }

            // 2. Plan is old (over 60 days)
            if (!$shouldRegenerate && $plan['generated_at']) {
                $daysSinceGenerated = Clock::daysBetween(Clock::utcToLocalDate($plan['generated_at']), $today);
                if ($daysSinceGenerated > 60) {
                    $shouldRegenerate = true;
                    $reason = 'Plan older than 60 days';
                }
            }

            // 3. No pending tasks left (plan is depleted)
            if (!$shouldRegenerate && (int)$plan['pending_task_count'] === 0) {
                $shouldRegenerate = true;
                $reason = 'No pending tasks remaining';
            }

            // 4. Season changed since plan was generated
            if (!$shouldRegenerate && $plan['season'] !== $currentSeason) {
                $shouldRegenerate = true;
                $reason = "Season changed from {$plan['season']} to {$currentSeason}";
            }

            // 5. Plan is missing check tasks (unless the owner turned checks off)
            if (!$shouldRegenerate && !$checksOff && (int)$plan['pending_check_count'] === 0) {
                $shouldRegenerate = true;
                $reason = 'No pending check tasks';
            }

            if ($shouldRegenerate) {
                try {
                    $carePlanController->generateCarePlan($plan['plant_id'], $plan['user_id']);
                    $regenerated++;
                    error_log("Cron: Regenerated care plan for plant {$plan['plant_name']} (ID: {$plan['plant_id']}). Reason: {$reason}");
                } catch (Exception $e) {
                    $errors[] = "Failed to regenerate plan for plant {$plan['plant_id']}: " . $e->getMessage();
                }
            } else {
                $skipped++;
            }
        }

        Clock::useTimezone(null);
        $this->logCronRun('evaluate_plans', $regenerated, $skipped, $errors);

        return [
            'success' => true,
            'date' => $today,
            'season' => $currentSeason,
            'plants_without_plan' => count($planlessPlants),
            'plans_evaluated' => count($plans),
            'plans_regenerated' => $regenerated,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * Send yourself a test reminder email.
     * GET /cron/test-email (signed-in users only; always goes to your own address)
     */
    public function testEmail(array $params, array $body, ?int $userId): array
    {
        if (!$userId) {
            return ['status' => 401, 'data' => ['error' => 'Authentication required']];
        }

        $stmt = db()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['status' => 404, 'data' => ['error' => 'User not found']];
        }

        $today = Clock::today();
        $tasks = (new CareScheduler())->tasksForToday($userId, false);

        // If no tasks, use sample tasks so the email can still be checked
        if (empty($tasks)) {
            $tasks = [
                ['id' => 0, 'task_type' => 'water', 'due_date' => $today, 'priority' => 'high',
                 'plant_name' => 'Sample Plant', 'plant_location' => 'Living Room'],
                ['id' => 0, 'task_type' => 'fertilize', 'due_date' => $today, 'priority' => 'normal',
                 'plant_name' => 'Another Plant', 'plant_location' => 'Bedroom'],
            ];
        }

        $userName = ucfirst(explode('@', $user['email'])[0]);
        $result = (new EmailService())->sendDailyReminder($user['email'], $tasks, $userName);

        return [
            'success' => $result,
            'email' => $user['email'],
            'task_count' => count($tasks),
            'message' => $result ? 'Test email sent!' : 'Failed to send email'
        ];
    }
}
