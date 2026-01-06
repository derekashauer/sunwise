<?php
/**
 * Email Service for Magic Links and Daily Reminders
 */

class EmailService
{
    /**
     * Send daily task reminder email
     */
    public function sendDailyReminder(string $email, array $tasks, string $userName = ''): bool
    {
        $taskCount = count($tasks);
        $subject = "🌱 You have {$taskCount} plant task" . ($taskCount !== 1 ? 's' : '') . " today";

        // Group tasks by task type for summary
        $tasksByType = [];
        foreach ($tasks as $task) {
            $type = $task['task_type'];
            if (!isset($tasksByType[$type])) {
                $tasksByType[$type] = [];
            }
            $tasksByType[$type][] = $task;
        }

        // Build task list HTML
        $taskListHtml = '';
        $taskListText = '';

        // Task type icons (emoji fallbacks for email)
        $taskIcons = [
            'water' => '💧',
            'fertilize' => '🌿',
            'trim' => '✂️',
            'repot' => '🪴',
            'rotate' => '🔄',
            'mist' => '💨',
            'check' => '👁️',
            'change_water' => '🔄💧',
            'check_roots' => '🌱',
            'pot_up' => '🪴'
        ];

        $priorityColors = [
            'urgent' => '#dc2626',
            'high' => '#f59e0b',
            'normal' => '#6b7280',
            'low' => '#9ca3af'
        ];

        foreach ($tasks as $task) {
            $icon = $taskIcons[$task['task_type']] ?? '📋';
            $taskType = ucfirst(str_replace('_', ' ', $task['task_type']));
            $plantName = htmlspecialchars($task['plant_name']);
            $priority = $task['priority'] ?? 'normal';
            $priorityColor = $priorityColors[$priority] ?? '#6b7280';

            $priorityBadge = '';
            if ($priority === 'urgent' || $priority === 'high') {
                $priorityLabel = ucfirst($priority);
                $priorityBadge = "<span style=\"background: {$priorityColor}; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px;\">{$priorityLabel}</span>";
            }

            $taskListHtml .= <<<HTML
            <div style="padding: 12px 16px; background: #f9fafb; border-radius: 12px; margin-bottom: 8px;">
                <div style="display: flex; align-items: center;">
                    <span style="font-size: 20px; margin-right: 12px;">{$icon}</span>
                    <div>
                        <div style="font-weight: 600; color: #111;">{$taskType}{$priorityBadge}</div>
                        <div style="color: #666; font-size: 14px;">{$plantName}</div>
                    </div>
                </div>
            </div>
HTML;

            $priorityText = ($priority === 'urgent' || $priority === 'high') ? " [{$priority}]" : '';
            $taskListText .= "- {$icon} {$taskType}: {$task['plant_name']}{$priorityText}\n";
        }

        // Build summary by type
        $summaryHtml = '';
        $summaryText = '';
        foreach ($tasksByType as $type => $typeTasks) {
            $count = count($typeTasks);
            $icon = $taskIcons[$type] ?? '📋';
            $label = ucfirst(str_replace('_', ' ', $type));
            $summaryHtml .= "<span style=\"display: inline-block; background: #e5e7eb; padding: 4px 12px; border-radius: 16px; margin: 4px; font-size: 13px;\">{$icon} {$count} {$label}</span>";
            $summaryText .= "{$icon} {$count} {$label}, ";
        }
        $summaryText = rtrim($summaryText, ', ');

        $greeting = $userName ? "Hi {$userName}!" : "Good morning!";
        $appUrl = rtrim(APP_URL, '/');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f0fdf4; padding: 40px 20px; margin: 0;">
    <div style="max-width: 500px; margin: 0 auto; background: white; border-radius: 16px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="width: 56px; height: 56px; background: linear-gradient(135deg, #22c55e, #16a34a); border-radius: 16px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                <span style="font-size: 28px;">🌱</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; color: #111;">{$greeting}</h1>
            <p style="color: #666; margin: 8px 0 0;">Your plants need some love today</p>
        </div>

        <!-- Summary badges -->
        <div style="text-align: center; margin-bottom: 24px;">
            {$summaryHtml}
        </div>

        <!-- Task list -->
        <div style="margin-bottom: 24px;">
            {$taskListHtml}
        </div>

        <!-- CTA Button -->
        <a href="{$appUrl}" style="display: block; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; text-decoration: none; padding: 16px 24px; border-radius: 12px; text-align: center; font-weight: 600; font-size: 16px;">
            View All Tasks
        </a>

        <p style="color: #999; font-size: 12px; text-align: center; margin-top: 24px;">
            You're receiving this because you enabled daily reminders in Sunwise.<br>
            <a href="{$appUrl}/settings" style="color: #22c55e;">Manage preferences</a>
        </p>
    </div>
</body>
</html>
HTML;

        $textBody = <<<TEXT
{$greeting}

Your plants need some love today! You have {$taskCount} task(s):

{$taskListText}
Summary: {$summaryText}

Open Sunwise to view all tasks: {$appUrl}

---
You're receiving this because you enabled daily reminders in Sunwise.
Manage preferences: {$appUrl}/settings
TEXT;

        return $this->send($email, $subject, $htmlBody, $textBody);
    }


    /**
     * Send magic link email
     */
    public function sendMagicLink(string $email, string $link): bool
    {
        $subject = 'Sign in to Sunwise';

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f0fdf4; padding: 40px 20px;">
    <div style="max-width: 400px; margin: 0 auto; background: white; border-radius: 16px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="width: 48px; height: 48px; background: #22c55e; border-radius: 12px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                <span style="font-size: 24px;">🌱</span>
            </div>
            <h1 style="margin: 0; font-size: 24px; color: #111;">Sign in to Sunwise</h1>
        </div>

        <p style="color: #666; text-align: center; margin-bottom: 24px;">
            Click the button below to sign in. This link expires in 15 minutes.
        </p>

        <a href="$link" style="display: block; background: #22c55e; color: white; text-decoration: none; padding: 14px 24px; border-radius: 12px; text-align: center; font-weight: 600;">
            Sign In
        </a>

        <p style="color: #999; font-size: 12px; text-align: center; margin-top: 24px;">
            If you didn't request this email, you can safely ignore it.
        </p>
    </div>
</body>
</html>
HTML;

        $textBody = "Sign in to Sunwise\n\nClick this link to sign in: $link\n\nThis link expires in 15 minutes.\n\nIf you didn't request this email, you can safely ignore it.";

        return $this->send($email, $subject, $htmlBody, $textBody);
    }

    /**
     * Send email using PHP mail or configured SMTP
     */
    private function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $boundary = md5(time());

        $headers = [
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_FROM,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"'
        ];

        $body = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--$boundary--";

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
}
