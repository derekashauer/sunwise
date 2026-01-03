<?php
/**
 * Settings Controller
 * Handles user settings including AI API key management
 */

class SettingsController
{
    /**
     * Get AI settings for user
     * GET /settings/ai
     */
    public function getAiSettings(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('SELECT * FROM ai_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $settings = $stmt->fetch();

        if (!$settings) {
            // Return defaults if no settings exist
            return [
                'default_provider' => 'openai',
                'has_claude_key' => false,
                'has_openai_key' => false,
                'claude_key_added_at' => null,
                'openai_key_added_at' => null
            ];
        }

        return [
            'default_provider' => $settings['default_provider'] ?? 'openai',
            'has_claude_key' => !empty($settings['claude_api_key_encrypted']),
            'has_openai_key' => !empty($settings['openai_api_key_encrypted']),
            'claude_key_added_at' => $settings['claude_key_added_at'],
            'openai_key_added_at' => $settings['openai_key_added_at']
        ];
    }

    /**
     * Set Claude API key
     * POST /settings/ai/claude-key
     */
    public function setClaudeKey(array $params, array $body, ?int $userId): array
    {
        $apiKey = $body['api_key'] ?? '';

        if (empty($apiKey)) {
            return ['status' => 400, 'data' => ['error' => 'API key is required']];
        }

        // Validate the key format (Claude keys start with sk-ant-)
        // Accept any sk-ant- prefix (e.g., sk-ant-api03-, sk-ant-admin-, etc.)
        if (!preg_match('/^sk-ant-[a-zA-Z0-9-]+/', $apiKey)) {
            return ['status' => 400, 'data' => ['error' => 'Invalid Claude API key format. Keys should start with sk-ant-']];
        }

        // Skip validation - just check format and save.
        // Validation will happen when the key is actually used.

        // Encrypt the key
        try {
            $encryptedKey = EncryptionService::encrypt($apiKey);
        } catch (Exception $e) {
            return ['status' => 500, 'data' => ['error' => 'Failed to encrypt API key. Please contact support.']];
        }

        // Upsert settings
        $stmt = db()->prepare('
            INSERT INTO ai_settings (user_id, claude_api_key_encrypted, claude_key_added_at)
            VALUES (?, ?, datetime("now"))
            ON CONFLICT(user_id) DO UPDATE SET
                claude_api_key_encrypted = excluded.claude_api_key_encrypted,
                claude_key_added_at = datetime("now"),
                updated_at = datetime("now")
        ');
        $stmt->execute([$userId, $encryptedKey]);

        return [
            'success' => true,
            'message' => 'Claude API key saved successfully',
            'masked_key' => EncryptionService::maskKey($apiKey)
        ];
    }

    /**
     * Set OpenAI API key
     * POST /settings/ai/openai-key
     */
    public function setOpenAIKey(array $params, array $body, ?int $userId): array
    {
        $apiKey = $body['api_key'] ?? '';

        if (empty($apiKey)) {
            return ['status' => 400, 'data' => ['error' => 'API key is required']];
        }

        // Validate the key format (OpenAI keys start with sk-)
        // Accept sk- or sk-proj- prefixes (project API keys)
        if (!preg_match('/^sk-[a-zA-Z0-9-]+/', $apiKey)) {
            return ['status' => 400, 'data' => ['error' => 'Invalid OpenAI API key format. Keys should start with sk-']];
        }

        // Skip validation for now - just check format and save
        // OpenAI key format is valid at this point, save it directly

        // Encrypt the key
        try {
            $encryptedKey = EncryptionService::encrypt($apiKey);
        } catch (Exception $e) {
            return ['status' => 500, 'data' => ['error' => 'Failed to encrypt API key. Please contact support.']];
        }

        // Upsert settings
        $stmt = db()->prepare('
            INSERT INTO ai_settings (user_id, openai_api_key_encrypted, openai_key_added_at)
            VALUES (?, ?, datetime("now"))
            ON CONFLICT(user_id) DO UPDATE SET
                openai_api_key_encrypted = excluded.openai_api_key_encrypted,
                openai_key_added_at = datetime("now"),
                updated_at = datetime("now")
        ');
        $stmt->execute([$userId, $encryptedKey]);

        return [
            'success' => true,
            'message' => 'OpenAI API key saved successfully',
            'masked_key' => EncryptionService::maskKey($apiKey)
        ];
    }

    /**
     * Remove Claude API key
     * DELETE /settings/ai/claude-key
     */
    public function removeClaudeKey(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            UPDATE ai_settings
            SET claude_api_key_encrypted = NULL, claude_key_added_at = NULL, updated_at = datetime("now")
            WHERE user_id = ?
        ');
        $stmt->execute([$userId]);

        return ['success' => true, 'message' => 'Claude API key removed'];
    }

    /**
     * Remove OpenAI API key
     * DELETE /settings/ai/openai-key
     */
    public function removeOpenAIKey(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            UPDATE ai_settings
            SET openai_api_key_encrypted = NULL, openai_key_added_at = NULL, updated_at = datetime("now")
            WHERE user_id = ?
        ');
        $stmt->execute([$userId]);

        return ['success' => true, 'message' => 'OpenAI API key removed'];
    }

    /**
     * Set default AI provider
     * PUT /settings/ai/default-provider
     */
    public function setDefaultProvider(array $params, array $body, ?int $userId): array
    {
        $provider = $body['provider'] ?? '';

        if (!in_array($provider, ['claude', 'openai'])) {
            return ['status' => 400, 'data' => ['error' => 'Provider must be "claude" or "openai"']];
        }

        // Check if user has a key for this provider
        $stmt = db()->prepare('SELECT * FROM ai_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $settings = $stmt->fetch();

        if ($settings) {
            $keyField = $provider === 'openai' ? 'openai_api_key_encrypted' : 'claude_api_key_encrypted';
            if (empty($settings[$keyField])) {
                return ['status' => 400, 'data' => ['error' => "You need to add a {$provider} API key before setting it as default"]];
            }
        }

        // Upsert settings
        $stmt = db()->prepare('
            INSERT INTO ai_settings (user_id, default_provider)
            VALUES (?, ?)
            ON CONFLICT(user_id) DO UPDATE SET
                default_provider = excluded.default_provider,
                updated_at = datetime("now")
        ');
        $stmt->execute([$userId, $provider]);

        return ['success' => true, 'default_provider' => $provider];
    }

    /**
     * Get notification settings
     * GET /settings/notifications
     */
    public function getNotificationSettings(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT email_digest_enabled, email_digest_time, sms_enabled, sms_phone,
                   twilio_account_sid, twilio_phone_number,
                   CASE WHEN twilio_auth_token_encrypted IS NOT NULL THEN 1 ELSE 0 END as has_twilio_token
            FROM users WHERE id = ?
        ');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        return [
            'email_digest_enabled' => (bool)($user['email_digest_enabled'] ?? 0),
            'email_digest_time' => $user['email_digest_time'] ?? '08:00',
            'sms_enabled' => (bool)($user['sms_enabled'] ?? 0),
            'sms_phone' => $user['sms_phone'] ?? '',
            'twilio_account_sid' => $user['twilio_account_sid'] ?? '',
            'twilio_phone_number' => $user['twilio_phone_number'] ?? '',
            'has_twilio_token' => (bool)($user['has_twilio_token'] ?? 0)
        ];
    }

    /**
     * Update email digest settings
     * PUT /settings/notifications/email-digest
     */
    public function updateEmailDigest(array $params, array $body, ?int $userId): array
    {
        $enabled = isset($body['enabled']) ? (int)(bool)$body['enabled'] : null;
        $time = $body['time'] ?? null;

        // Validate time format
        if ($time !== null && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            return ['status' => 400, 'data' => ['error' => 'Invalid time format. Use HH:MM (24-hour)']];
        }

        $updates = [];
        $values = [];

        if ($enabled !== null) {
            $updates[] = 'email_digest_enabled = ?';
            $values[] = $enabled;
        }
        if ($time !== null) {
            $updates[] = 'email_digest_time = ?';
            $values[] = $time;
        }

        if (empty($updates)) {
            return ['status' => 400, 'data' => ['error' => 'No settings to update']];
        }

        $values[] = $userId;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($values);

        return ['success' => true];
    }

    /**
     * Update SMS settings
     * PUT /settings/notifications/sms
     */
    public function updateSmsSettings(array $params, array $body, ?int $userId): array
    {
        $enabled = isset($body['enabled']) ? (int)(bool)$body['enabled'] : null;
        $phone = $body['phone'] ?? null;
        $twilioSid = $body['twilio_account_sid'] ?? null;
        $twilioToken = $body['twilio_auth_token'] ?? null;
        $twilioPhone = $body['twilio_phone_number'] ?? null;

        $updates = [];
        $values = [];

        if ($enabled !== null) {
            $updates[] = 'sms_enabled = ?';
            $values[] = $enabled;
        }
        if ($phone !== null) {
            $updates[] = 'sms_phone = ?';
            $values[] = $phone;
        }
        if ($twilioSid !== null) {
            $updates[] = 'twilio_account_sid = ?';
            $values[] = $twilioSid;
        }
        if ($twilioToken !== null) {
            $encryptedToken = EncryptionService::encrypt($twilioToken);
            $updates[] = 'twilio_auth_token_encrypted = ?';
            $values[] = $encryptedToken;
        }
        if ($twilioPhone !== null) {
            $updates[] = 'twilio_phone_number = ?';
            $values[] = $twilioPhone;
        }

        if (empty($updates)) {
            return ['status' => 400, 'data' => ['error' => 'No settings to update']];
        }

        $values[] = $userId;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($values);

        return ['success' => true];
    }

    /**
     * Get public gallery settings
     * GET /settings/public-gallery
     */
    public function getPublicGallerySettings(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT public_gallery_enabled, public_gallery_token, public_gallery_name
            FROM users WHERE id = ?
        ');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $baseUrl = rtrim(APP_URL, '/');

        return [
            'enabled' => (bool)($user['public_gallery_enabled'] ?? 0),
            'token' => $user['public_gallery_token'] ?? null,
            'name' => $user['public_gallery_name'] ?? null,
            'url' => $user['public_gallery_token'] ? "{$baseUrl}/gallery/{$user['public_gallery_token']}" : null
        ];
    }

    /**
     * Update public gallery settings
     * PUT /settings/public-gallery
     */
    public function updatePublicGallery(array $params, array $body, ?int $userId): array
    {
        $enabled = isset($body['enabled']) ? (int)(bool)$body['enabled'] : null;
        $name = $body['name'] ?? null;
        $regenerateToken = $body['regenerate_token'] ?? false;

        $updates = [];
        $values = [];

        if ($enabled !== null) {
            $updates[] = 'public_gallery_enabled = ?';
            $values[] = $enabled;

            // Generate token if enabling and no token exists
            if ($enabled) {
                $stmt = db()->prepare('SELECT public_gallery_token FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                if (empty($user['public_gallery_token'])) {
                    $regenerateToken = true;
                }
            }
        }

        if ($name !== null) {
            $updates[] = 'public_gallery_name = ?';
            $values[] = $name;
        }

        if ($regenerateToken) {
            $token = bin2hex(random_bytes(16));
            $updates[] = 'public_gallery_token = ?';
            $values[] = $token;
        }

        if (empty($updates)) {
            return ['status' => 400, 'data' => ['error' => 'No settings to update']];
        }

        $values[] = $userId;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($values);

        // Return updated settings
        return $this->getPublicGallerySettings($params, $body, $userId);
    }
}
