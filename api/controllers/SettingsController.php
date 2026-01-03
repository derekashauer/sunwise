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
}
