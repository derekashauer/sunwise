<?php
/**
 * Email Service for Magic Links
 */

class EmailService
{
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
