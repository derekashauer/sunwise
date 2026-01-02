<?php
/**
 * Task Controller
 */

class TaskController
{
    /**
     * Get today's tasks
     */
    public function today(array $params, array $body, ?int $userId): array
    {
        $today = date('Y-m-d');

        $stmt = db()->prepare('
            SELECT t.*,
                   p.name as plant_name,
                   p.location as plant_location,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as plant_thumbnail
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE p.user_id = ?
              AND t.due_date <= ?
              AND t.skipped_at IS NULL
            ORDER BY
                CASE WHEN t.completed_at IS NULL THEN 0 ELSE 1 END,
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

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Get upcoming tasks (next 7 days)
     */
    public function upcoming(array $params, array $body, ?int $userId): array
    {
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));

        $stmt = db()->prepare('
            SELECT t.*,
                   p.name as plant_name,
                   p.location as plant_location,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as plant_thumbnail
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE p.user_id = ?
              AND t.due_date BETWEEN ? AND ?
              AND t.completed_at IS NULL
              AND t.skipped_at IS NULL
            ORDER BY t.due_date ASC, t.priority ASC
        ');
        $stmt->execute([$userId, $today, $endDate]);

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Get tasks for specific plant
     */
    public function forPlant(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $stmt = db()->prepare('
            SELECT *
            FROM tasks
            WHERE plant_id = ?
              AND skipped_at IS NULL
            ORDER BY
                CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END,
                due_date ASC
            LIMIT 20
        ');
        $stmt->execute([$plantId]);

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Complete a task
     */
    public function complete(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $notes = $body['notes'] ?? null;

        // Verify task ownership
        $stmt = db()->prepare('
            SELECT t.*, p.user_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task || $task['user_id'] != $userId) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Mark as completed
        $stmt = db()->prepare('UPDATE tasks SET completed_at = datetime("now"), notes = ? WHERE id = ?');
        $stmt->execute([$notes, $taskId]);

        // Log to care log
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            $task['task_type'],
            $notes
        ]);

        // Generate next occurrence if recurring
        if ($task['recurrence']) {
            $this->generateNextOccurrence($task);
        }

        // Get updated task
        $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);

        return ['task' => $stmt->fetch()];
    }

    /**
     * Skip a task
     */
    public function skip(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $reason = $body['reason'] ?? null;

        // Verify task ownership
        $stmt = db()->prepare('
            SELECT t.*, p.user_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task || $task['user_id'] != $userId) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Mark as skipped
        $stmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
        $stmt->execute([$reason, $taskId]);

        // Log to care log with negative outcome
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, outcome)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            'skipped_' . $task['task_type'],
            $reason,
            'neutral'
        ]);

        // Generate next occurrence if recurring
        if ($task['recurrence']) {
            $this->generateNextOccurrence($task);
        }

        return ['task' => $task];
    }

    /**
     * Generate next occurrence of recurring task
     */
    private function generateNextOccurrence(array $task): void
    {
        $recurrence = json_decode($task['recurrence'], true);
        if (!$recurrence) return;

        $interval = $recurrence['interval'] ?? 7;
        $type = $recurrence['type'] ?? 'days';

        $nextDate = date('Y-m-d', strtotime($task['due_date'] . " +$interval $type"));

        $stmt = db()->prepare('
            INSERT INTO tasks (care_plan_id, plant_id, task_type, due_date, recurrence, instructions, priority)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['care_plan_id'],
            $task['plant_id'],
            $task['task_type'],
            $nextDate,
            $task['recurrence'],
            $task['instructions'],
            $task['priority']
        ]);
    }
}
