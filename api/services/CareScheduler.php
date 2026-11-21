<?php
/**
 * Care Scheduler
 *
 * Every rule about when a care task comes due lives here, so the owner, the
 * sitter, bulk actions, the daily email and the care log all behave the same
 * way. Before this, each path had its own copy and they drifted apart.
 *
 * Fertilizer has two modes, set per owner in Settings:
 * - "separate": slow-release granules like Osmocote. Fertilizing does not
 *   water the plant, so water and fertilize tasks are fully independent.
 *   Fertilize repeats about every 120 days.
 * - "mixed": liquid fertilizer mixed into the watering can. Fertilizing also
 *   waters the plant, so it resets the water task.
 * With winter rest on (the default), fertilize tasks never land between
 * September and February; they move to March 1.
 */

class CareScheduler
{
    public const TASK_TYPES = [
        'water', 'fertilize', 'mist', 'rotate', 'trim', 'repot',
        'check', 'change_water', 'check_roots', 'pot_up',
    ];

    /** Days until a skipped task comes back. Shorter than a full cycle. */
    public const SKIP_DELAYS = [
        'water' => 1,
        'mist' => 1,
        'change_water' => 2,
        'check' => 3,
        'fertilize' => 7,
        'rotate' => 7,
        'trim' => 7,
        'repot' => 14,
        'pot_up' => 14,
        'check_roots' => 14,
    ];

    /** Slow-release granules: default and minimum days between feedings. */
    public const SEPARATE_FERTILIZE_INTERVAL = 120;
    public const SEPARATE_FERTILIZE_MIN_INTERVAL = 90;

    /** Fresh potting mix usually has fertilizer in it; wait this long after repotting. */
    public const REPOT_FERTILIZE_PAUSE_DAYS = 90;

    /** Care log actions that count as a task of the same type. */
    public const ACTION_TO_TASK = [
        'water' => 'water',
        'fertilize' => 'fertilize',
        'mist' => 'mist',
        'rotate' => 'rotate',
        'prune' => 'trim',
        'trim' => 'trim',
        'repot' => 'repot',
        'change_water' => 'change_water',
        'check_roots' => 'check_roots',
        'pot_up' => 'pot_up',
    ];

    private array $ownerSettingsCache = [];

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    /**
     * Load a full task row with its plant's owner.
     */
    public static function loadTask(int $taskId): ?array
    {
        $stmt = db()->prepare('
            SELECT t.*, p.user_id AS owner_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The plant owner's care settings.
     */
    public function ownerSettings(int $plantId): array
    {
        if (isset($this->ownerSettingsCache[$plantId])) {
            return $this->ownerSettingsCache[$plantId];
        }
        $stmt = db()->prepare('
            SELECT u.id AS user_id, u.fertilizer_method, u.winter_rest, u.timezone
            FROM plants p JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ');
        $stmt->execute([$plantId]);
        $row = $stmt->fetch() ?: [];
        $settings = [
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'fertilizer_method' => ($row['fertilizer_method'] ?? 'separate') === 'mixed' ? 'mixed' : 'separate',
            'winter_rest' => !isset($row['winter_rest']) || (int) $row['winter_rest'] === 1,
        ];
        return $this->ownerSettingsCache[$plantId] = $settings;
    }

    /**
     * Task types this user has turned off in Settings.
     */
    public static function disabledTypes(int $userId): array
    {
        $stmt = db()->prepare('SELECT task_type FROM task_type_settings WHERE user_id = ? AND enabled = 0');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * SQL condition (alias p) for plants this user owns or shares through a
     * household. Only whole numbers are interpolated.
     */
    public static function accessiblePlantCondition(int $userId): string
    {
        $stmt = db()->prepare('SELECT household_id FROM household_members WHERE user_id = ?');
        $stmt->execute([$userId]);
        $households = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $userId = (int) $userId;

        if (empty($households)) {
            return "p.user_id = {$userId}";
        }

        $list = implode(',', $households);
        return "(p.user_id = {$userId} OR p.id IN (SELECT plant_id FROM household_plants WHERE household_id IN ({$list})))";
    }

    /**
     * Whether a user owns a plant or shares it through a household.
     */
    public static function canAccessPlant(int $plantId, int $userId): bool
    {
        $stmt = db()->prepare('SELECT user_id FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId === false) {
            return false;
        }
        if ((int) $ownerId === $userId) {
            return true;
        }
        $stmt = db()->prepare('
            SELECT 1 FROM household_plants hp
            JOIN household_members hm ON hp.household_id = hm.household_id
            WHERE hp.plant_id = ? AND hm.user_id = ?
        ');
        $stmt->execute([$plantId, $userId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Interval overrides the owner set by hand, keyed by task type.
     */
    public static function overrides(int $plantId): array
    {
        $stmt = db()->prepare('SELECT task_type, interval_days FROM plant_schedule_overrides WHERE plant_id = ?');
        $stmt->execute([$plantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['task_type']] = (int) $row['interval_days'];
        }
        return $out;
    }

    /**
     * Days between repeats for a task: the owner's override first, then the
     * task's own recurrence. Slow-release fertilizer never repeats faster
     * than every 90 days.
     */
    public function intervalFor(array $task): ?int
    {
        $overrides = self::overrides((int) $task['plant_id']);
        $interval = $overrides[$task['task_type']] ?? self::recurrenceDays($task['recurrence'] ?? null);

        if ($task['task_type'] === 'fertilize'
            && $this->ownerSettings((int) $task['plant_id'])['fertilizer_method'] === 'separate'
            && !isset($overrides['fertilize'])) {
            if ($interval === null || $interval < self::SEPARATE_FERTILIZE_MIN_INTERVAL) {
                $interval = self::SEPARATE_FERTILIZE_INTERVAL;
            }
        }

        return $interval;
    }

    /**
     * Convert a recurrence JSON string to a number of days.
     */
    public static function recurrenceDays(?string $recurrence): ?int
    {
        if (!$recurrence) {
            return null;
        }
        $data = json_decode($recurrence, true);
        if (!is_array($data)) {
            return null;
        }
        $interval = max(1, (int) ($data['interval'] ?? 7));
        $type = $data['type'] ?? 'days';
        if ($type === 'weeks') {
            return $interval * 7;
        }
        if ($type === 'months') {
            return $interval * 30;
        }
        return $interval;
    }

    // ------------------------------------------------------------------
    // Due date rules
    // ------------------------------------------------------------------

    /**
     * Apply fertilizer rules to a proposed due date: wait after a repot, and
     * skip the winter rest months.
     */
    public function normalizeDueDate(array $task, string $date): string
    {
        if ($task['task_type'] !== 'fertilize') {
            return $date;
        }

        $plantId = (int) $task['plant_id'];

        $stmt = db()->prepare("
            SELECT MAX(performed_at) FROM care_log
            WHERE plant_id = ? AND action IN ('repot', 'pot_up')
        ");
        $stmt->execute([$plantId]);
        $lastRepot = $stmt->fetchColumn();
        if ($lastRepot) {
            $earliest = Clock::addDays(Clock::utcToLocalDate($lastRepot), self::REPOT_FERTILIZE_PAUSE_DAYS);
            if ($date < $earliest) {
                $date = $earliest;
            }
        }

        if ($this->ownerSettings($plantId)['winter_rest'] && Clock::isRestMonth($date)) {
            $date = Clock::nextMarchFirst($date);
        }

        return $date;
    }

    /**
     * Create the next task in a recurring chain.
     *
     * Modes:
     * - complete: a full interval after the later of the due date or today.
     * - reset:    a full interval from today (the care was just done another way).
     * - skip:     a short delay so a skipped task comes back soon.
     *
     * @return int|null The new task's id, or null if none was created.
     */
    public function generateNextOccurrence(int $taskId, string $mode = 'complete', ?int $delayDays = null): ?int
    {
        $task = self::loadTask($taskId);
        if (!$task || empty($task['recurrence'])) {
            return null;
        }

        $interval = $this->intervalFor($task);
        if (!$interval) {
            return null;
        }

        $today = Clock::today();
        if ($mode === 'skip') {
            $delay = $delayDays ?? min(self::SKIP_DELAYS[$task['task_type']] ?? 3, $interval);
            $nextDate = Clock::addDays($today, max(1, $delay));
        } elseif ($mode === 'reset') {
            $nextDate = Clock::addDays($today, $interval);
        } else {
            $fromDue = Clock::addDays($task['due_date'], $interval);
            $fromToday = Clock::addDays($today, $interval);
            $nextDate = max($fromDue, $fromToday);
        }

        $nextDate = $this->normalizeDueDate($task, $nextDate);

        // Never stack two pending tasks of the same type on one plant
        $stmt = db()->prepare('
            SELECT id FROM tasks
            WHERE plant_id = ? AND task_type = ? AND completed_at IS NULL AND skipped_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$task['plant_id'], $task['task_type']]);
        if ($stmt->fetch()) {
            return null;
        }

        $recurrence = json_decode($task['recurrence'], true) ?: [];
        $recurrence['type'] = 'days';
        $recurrence['interval'] = $interval;

        $stmt = db()->prepare('
            INSERT INTO tasks (care_plan_id, plant_id, task_type, due_date, recurrence, instructions, priority, generated_from_task_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['care_plan_id'],
            $task['plant_id'],
            $task['task_type'],
            $nextDate,
            json_encode($recurrence),
            $task['instructions'],
            $task['priority'] ?: 'normal',
            $task['id'],
        ]);

        return (int) db()->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Mark a task done, log it and continue the schedule. Safe to call twice.
     *
     * @param bool $writeLog False when the caller already wrote the care log entry.
     */
    public function complete(array $task, ?int $userId, ?string $notes = null, ?array $checkData = null, bool $writeLog = true): array
    {
        if (!empty($task['completed_at'])) {
            return self::loadTask((int) $task['id']);
        }

        $stmt = db()->prepare('
            UPDATE tasks
            SET completed_at = ?, notes = ?, completed_by_user_id = ?, skipped_at = NULL, skip_reason = NULL
            WHERE id = ? AND completed_at IS NULL
        ');
        $stmt->execute([Clock::utcNow(), $notes, $userId, $task['id']]);
        if ($stmt->rowCount() === 0) {
            // Someone else finished it a moment ago
            return self::loadTask((int) $task['id']);
        }

        if ($writeLog) {
            $stmt = db()->prepare('
                INSERT INTO care_log (plant_id, task_id, action, notes, performed_by_user_id, check_data)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $task['plant_id'],
                $task['id'],
                $task['task_type'],
                $notes,
                $userId,
                $checkData ? json_encode($checkData) : null,
            ]);
        }

        $this->runCompletionSideEffects($task);

        return self::loadTask((int) $task['id']);
    }

    /**
     * What happens after any completion: continue the chain, reset the water
     * task when liquid fertilizer also watered the plant, and hold off
     * fertilizing after a repot.
     */
    public function runCompletionSideEffects(array $task): void
    {
        $this->generateNextOccurrence((int) $task['id']);

        $plantId = (int) $task['plant_id'];

        if ($task['task_type'] === 'fertilize' && $this->ownerSettings($plantId)['fertilizer_method'] === 'mixed') {
            $stmt = db()->prepare('
                SELECT id FROM tasks
                WHERE plant_id = ? AND task_type = "water" AND completed_at IS NULL AND skipped_at IS NULL
                ORDER BY due_date ASC LIMIT 1
            ');
            $stmt->execute([$plantId]);
            $waterId = $stmt->fetchColumn();
            if ($waterId) {
                $water = self::loadTask((int) $waterId);
                $this->skip($water, null, 'Auto-skipped: plant fertilized (fertilizer mixed with water)', 'reset', null, (int) $task['id'], false);
            }
        }

        if (in_array($task['task_type'], ['repot', 'pot_up'], true)) {
            $this->normalizePendingFertilize($plantId);
        }
    }

    /**
     * Skip a task and bring it back later.
     *
     * @param string   $mode           'skip' (short delay), 'reset' or 'complete'.
     * @param int|null $delayDays      Exact delay for 'skip' mode.
     * @param int|null $skippedByTask  The completed task that caused this skip, for undo.
     */
    public function skip(array $task, ?int $userId, ?string $reason, string $mode = 'skip', ?int $delayDays = null, ?int $skippedByTask = null, bool $writeLog = true): void
    {
        if (!empty($task['completed_at']) || !empty($task['skipped_at'])) {
            return;
        }

        $stmt = db()->prepare('
            UPDATE tasks SET skipped_at = ?, skip_reason = ?, skipped_by_task_id = ?
            WHERE id = ? AND completed_at IS NULL AND skipped_at IS NULL
        ');
        $stmt->execute([Clock::utcNow(), $reason, $skippedByTask, $task['id']]);
        if ($stmt->rowCount() === 0) {
            return;
        }

        if ($writeLog) {
            $stmt = db()->prepare('
                INSERT INTO care_log (plant_id, task_id, action, notes, outcome, performed_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $task['plant_id'],
                $task['id'],
                'skipped_' . $task['task_type'],
                $reason,
                'neutral',
                $userId,
            ]);
        }

        $this->generateNextOccurrence((int) $task['id'], $mode, $delayDays);
    }

    /**
     * Undo a completion: put the task back, remove its log entry and the
     * follow-up task it created, and restore anything it auto-skipped.
     */
    public function uncomplete(array $task): array
    {
        if (empty($task['completed_at'])) {
            return $task;
        }

        $db = db();
        $db->beginTransaction();
        try {
            $this->deletePendingGeneratedFrom((int) $task['id']);

            $stmt = $db->prepare('SELECT id FROM tasks WHERE skipped_by_task_id = ?');
            $stmt->execute([$task['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $siblingId) {
                $this->deletePendingGeneratedFrom((int) $siblingId);
                $db->prepare('UPDATE tasks SET skipped_at = NULL, skip_reason = NULL, skipped_by_task_id = NULL WHERE id = ?')
                    ->execute([$siblingId]);
            }

            $db->prepare('
                DELETE FROM care_log WHERE id = (
                    SELECT id FROM care_log WHERE task_id = ? AND action = ? ORDER BY id DESC LIMIT 1
                )
            ')->execute([$task['id'], $task['task_type']]);

            $db->prepare('UPDATE tasks SET completed_at = NULL, notes = NULL, completed_by_user_id = NULL WHERE id = ?')
                ->execute([$task['id']]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return self::loadTask((int) $task['id']);
    }

    private function deletePendingGeneratedFrom(int $taskId): void
    {
        db()->prepare('
            DELETE FROM tasks
            WHERE generated_from_task_id = ? AND completed_at IS NULL AND skipped_at IS NULL
        ')->execute([$taskId]);
    }

    /**
     * Move a pending task to another day without logging a skip. A pinned
     * date is one the daily cleanup won't move.
     */
    public function snooze(array $task, string $date, bool $pin = false): array
    {
        db()->prepare('UPDATE tasks SET due_date = ?, due_pinned = ? WHERE id = ? AND completed_at IS NULL AND skipped_at IS NULL')
            ->execute([$date, $pin ? 1 : 0, $task['id']]);
        return self::loadTask((int) $task['id']);
    }

    /**
     * Save a hand-picked interval for one plant and task type. Rebuilds keep it.
     *
     * @return int The interval actually saved (kept between 1 and 365 days).
     */
    public function setOverride(int $plantId, string $taskType, int $days): int
    {
        $days = max(1, min(365, $days));

        db()->prepare('
            INSERT INTO plant_schedule_overrides (plant_id, task_type, interval_days, updated_at)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(plant_id, task_type) DO UPDATE SET interval_days = excluded.interval_days, updated_at = excluded.updated_at
        ')->execute([$plantId, $taskType, $days, Clock::utcNow()]);

        db()->prepare('
            UPDATE tasks SET recurrence = ?
            WHERE plant_id = ? AND task_type = ? AND completed_at IS NULL AND skipped_at IS NULL
        ')->execute([json_encode(['type' => 'days', 'interval' => $days]), $plantId, $taskType]);

        return $days;
    }

    /**
     * Push pending fertilize tasks for a plant out of the rest months and the
     * post-repot pause. Only ever moves dates later.
     */
    public function normalizePendingFertilize(int $plantId): void
    {
        $stmt = db()->prepare('
            SELECT * FROM tasks
            WHERE plant_id = ? AND task_type = "fertilize" AND completed_at IS NULL AND skipped_at IS NULL
        ');
        $stmt->execute([$plantId]);
        foreach ($stmt->fetchAll() as $task) {
            $date = $this->normalizeDueDate($task, $task['due_date']);
            if ($date > $task['due_date']) {
                db()->prepare('UPDATE tasks SET due_date = ? WHERE id = ?')->execute([$date, $task['id']]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Daily cleanup
    // ------------------------------------------------------------------

    /**
     * Bring pending tasks in line with what actually happened. Runs when the
     * Today list loads and before the daily email.
     *
     * 1. Turned-off task types: retire their pending tasks on plants this user owns.
     * 2. Water/fertilize that were recently done another way move out.
     * 3. Fertilize dates follow the rest months and repot pause.
     * 4. Liquid fertilizer only: when water and fertilize are both due, the
     *    fertilize task covers the watering.
     */
    public function reconcile(int $userId): void
    {
        $today = Clock::today();
        $access = self::accessiblePlantCondition($userId);
        $disabled = self::disabledTypes($userId);

        // 1. Retire tasks of turned-off types on the user's own plants
        if (!empty($disabled)) {
            $placeholders = implode(',', array_fill(0, count($disabled), '?'));
            $stmt = db()->prepare("
                UPDATE tasks SET skipped_at = ?, skip_reason = 'Task type turned off in Settings'
                WHERE completed_at IS NULL AND skipped_at IS NULL
                  AND task_type IN ({$placeholders})
                  AND plant_id IN (SELECT id FROM plants WHERE user_id = ?)
            ");
            $stmt->execute(array_merge([Clock::utcNow()], $disabled, [$userId]));
        }

        $activeTypes = array_values(array_diff(['water', 'fertilize'], $disabled));
        if (empty($activeTypes)) {
            return;
        }
        $typePlaceholders = implode(',', array_fill(0, count($activeTypes), '?'));

        // 2. Recently done another way (pinned due dates are left alone)
        $stmt = db()->prepare("
            SELECT t.* FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE {$access}
              AND t.task_type IN ({$typePlaceholders})
              AND t.completed_at IS NULL AND t.skipped_at IS NULL
              AND t.due_date <= ?
              AND COALESCE(t.due_pinned, 0) = 0
              AND p.archived_at IS NULL
        ");
        $stmt->execute(array_merge($activeTypes, [$today]));

        foreach ($stmt->fetchAll() as $task) {
            $settings = $this->ownerSettings((int) $task['plant_id']);
            $actions = ($task['task_type'] === 'water' && $settings['fertilizer_method'] === 'mixed')
                ? ['water', 'fertilize']
                : [$task['task_type']];
            $placeholders = implode(',', array_fill(0, count($actions), '?'));

            $logStmt = db()->prepare("SELECT MAX(performed_at) FROM care_log WHERE plant_id = ? AND action IN ({$placeholders})");
            $logStmt->execute(array_merge([$task['plant_id']], $actions));
            $last = $logStmt->fetchColumn();
            if (!$last) {
                continue;
            }

            $interval = $this->intervalFor($task);
            if (!$interval) {
                continue;
            }
            $lastDate = Clock::utcToLocalDate($last);
            if (Clock::daysBetween($lastDate, $today) < $interval) {
                $newDue = $this->normalizeDueDate($task, Clock::addDays($lastDate, $interval));
                if ($newDue > $today) {
                    db()->prepare('UPDATE tasks SET due_date = ? WHERE id = ?')->execute([$newDue, $task['id']]);
                }
            }
        }

        // 3. Fertilize rest months and repot pause
        if (in_array('fertilize', $activeTypes, true)) {
            $stmt = db()->query("
                SELECT DISTINCT t.plant_id FROM tasks t
                JOIN plants p ON t.plant_id = p.id
                WHERE {$access}
                  AND t.task_type = 'fertilize'
                  AND t.completed_at IS NULL AND t.skipped_at IS NULL
                  AND p.archived_at IS NULL
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $plantId) {
                $this->normalizePendingFertilize((int) $plantId);
            }
        }

        // 4. Liquid fertilizer covers the watering on the same day
        if (count($activeTypes) === 2) {
            $stmt = db()->prepare("
                SELECT t.id, t.plant_id, t.task_type FROM tasks t
                JOIN plants p ON t.plant_id = p.id
                WHERE {$access}
                  AND t.task_type IN ('water', 'fertilize')
                  AND t.completed_at IS NULL AND t.skipped_at IS NULL
                  AND t.due_date <= ?
                  AND p.archived_at IS NULL
            ");
            $stmt->execute([$today]);
            $byPlant = [];
            foreach ($stmt->fetchAll() as $row) {
                $byPlant[$row['plant_id']][$row['task_type']] = (int) $row['id'];
            }
            foreach ($byPlant as $plantId => $types) {
                if (!isset($types['water'], $types['fertilize'])) {
                    continue;
                }
                if ($this->ownerSettings((int) $plantId)['fertilizer_method'] !== 'mixed') {
                    continue;
                }
                $water = self::loadTask($types['water']);
                $this->skip($water, null, 'Auto-skipped: fertilize task covers watering', 'skip', 1, null, false);
            }
        }
    }

    // ------------------------------------------------------------------
    // Task lists
    // ------------------------------------------------------------------

    /**
     * Tasks due today or earlier for a user, plus the ones finished today.
     * Shared by the Today screen and the daily email so they always match.
     */
    public function tasksForToday(int $userId, bool $includeCompletedToday = true): array
    {
        $today = Clock::today();
        [$dayStart, $dayEnd] = Clock::localDayUtcRange($today);
        $access = self::accessiblePlantCondition($userId);
        $disabled = self::disabledTypes($userId);

        $params = [(int) $userId, $today];
        $typeClause = '';
        if (!empty($disabled)) {
            $typeClause = ' AND t.task_type NOT IN (' . implode(',', array_fill(0, count($disabled), '?')) . ')';
            $params = array_merge($params, $disabled);
        }

        $completedClause = 't.completed_at IS NULL';
        if ($includeCompletedToday) {
            $completedClause = '(t.completed_at IS NULL OR (t.completed_at >= ? AND t.completed_at < ?))';
            $params[] = $dayStart;
            $params[] = $dayEnd;
        }

        $stmt = db()->prepare("
            SELECT t.*,
                   p.name AS plant_name,
                   COALESCE(l.name, p.location) AS plant_location,
                   p.species AS plant_species,
                   p.light_condition AS plant_light_condition,
                   CAST(COALESCE(p.is_propagation, 0) AS INTEGER) AS plant_is_propagation,
                   p.soil_type AS plant_soil_type,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) AS plant_thumbnail,
                   CASE WHEN p.user_id = ? THEN 1 ELSE 0 END AS is_owned,
                   CASE WHEN t.completed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, '@') - 1))
                        FROM users u WHERE u.id = t.completed_by_user_id)
                   ELSE NULL END AS completed_by_name,
                   (SELECT MAX(cl.performed_at) FROM care_log cl
                    WHERE cl.plant_id = t.plant_id AND cl.action = t.task_type) AS last_done_at
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE {$access}
              AND t.due_date <= ?
              AND t.skipped_at IS NULL
              AND p.archived_at IS NULL
              {$typeClause}
              AND {$completedClause}
            ORDER BY
                CASE WHEN t.completed_at IS NULL THEN 0 ELSE 1 END,
                t.due_date ASC,
                CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END
        ");
        $stmt->execute($params);

        return array_map(fn($task) => $this->decorate($task, $today), $stmt->fetchAll());
    }

    /**
     * Add the fields the app shows on every task row.
     */
    public function decorate(array $task, ?string $today = null): array
    {
        $today = $today ?? Clock::today();
        $task['days_overdue'] = empty($task['completed_at'])
            ? max(0, Clock::daysBetween($task['due_date'], $today))
            : 0;
        $task['interval_days'] = $this->intervalFor($task);
        return $task;
    }
}
