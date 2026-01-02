<?php
/**
 * Authentication Controller
 */

class AuthController
{
    private AuthMiddleware $auth;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
    }

    /**
     * Register new user
     */
    public function register(array $params, array $body, ?int $userId): array
    {
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 400, 'data' => ['error' => 'Invalid email address']];
        }

        if (strlen($password) < 8) {
            return ['status' => 400, 'data' => ['error' => 'Password must be at least 8 characters']];
        }

        // Check if email exists
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['status' => 400, 'data' => ['error' => 'Email already registered']];
        }

        // Create user
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $passwordHash]);

        $newUserId = db()->lastInsertId();

        // Generate token
        $token = $this->auth->generateToken($newUserId);

        return [
            'token' => $token,
            'user' => [
                'id' => $newUserId,
                'email' => $email,
                'created_at' => date('c')
            ]
        ];
    }

    /**
     * Login with email/password
     */
    public function login(array $params, array $body, ?int $userId): array
    {
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            return ['status' => 400, 'data' => ['error' => 'Email and password required']];
        }

        // Find user
        $stmt = db()->prepare('SELECT id, email, password_hash, created_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['status' => 401, 'data' => ['error' => 'Invalid email or password']];
        }

        // Generate token
        $token = $this->auth->generateToken($user['id']);

        return [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'created_at' => $user['created_at']
            ]
        ];
    }

    /**
     * Request magic link
     */
    public function requestMagicLink(array $params, array $body, ?int $userId): array
    {
        $email = trim($body['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 400, 'data' => ['error' => 'Invalid email address']];
        }

        // Find or create user
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Create new user without password
            $stmt = db()->prepare('INSERT INTO users (email) VALUES (?)');
            $stmt->execute([$email]);
            $userIdForMagic = db()->lastInsertId();
        } else {
            $userIdForMagic = $user['id'];
        }

        // Generate magic token
        $magicToken = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = db()->prepare('UPDATE users SET magic_token = ?, magic_token_expires = ? WHERE id = ?');
        $stmt->execute([$magicToken, $expires, $userIdForMagic]);

        // Send email (simplified - in production use proper email service)
        $link = APP_URL . '/verify/' . $magicToken;

        // For now, just log it (in production, send actual email)
        error_log("Magic link for $email: $link");

        // Try to send email
        $emailService = new EmailService();
        $emailService->sendMagicLink($email, $link);

        return ['message' => 'Magic link sent to your email'];
    }

    /**
     * Verify magic link token
     */
    public function verifyMagicLink(array $params, array $body, ?int $userId): array
    {
        $token = $params['token'] ?? '';

        if (!$token) {
            return ['status' => 400, 'data' => ['error' => 'Token required']];
        }

        // Find user with valid token
        $stmt = db()->prepare('
            SELECT id, email, created_at
            FROM users
            WHERE magic_token = ? AND magic_token_expires > datetime("now")
        ');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['status' => 400, 'data' => ['error' => 'Invalid or expired token']];
        }

        // Clear magic token
        $stmt = db()->prepare('UPDATE users SET magic_token = NULL, magic_token_expires = NULL WHERE id = ?');
        $stmt->execute([$user['id']]);

        // Generate JWT
        $jwtToken = $this->auth->generateToken($user['id']);

        return [
            'token' => $jwtToken,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'created_at' => $user['created_at']
            ]
        ];
    }

    /**
     * Get current user
     */
    public function me(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('SELECT id, email, timezone, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['status' => 404, 'data' => ['error' => 'User not found']];
        }

        return [
            'user' => $user
        ];
    }

    /**
     * Logout (client-side token removal, but we can invalidate if needed)
     */
    public function logout(array $params, array $body, ?int $userId): array
    {
        return ['message' => 'Logged out successfully'];
    }
}
