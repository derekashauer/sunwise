<?php
/**
 * AI Service Interface
 * Common interface for all AI providers (Claude, OpenAI, etc.)
 */

interface AIServiceInterface
{
    /**
     * Identify plant species from an image
     *
     * @param string $imagePath Path to the plant image
     * @return array|null Identification results or null on failure
     */
    public function identifyPlant(string $imagePath): ?array;

    /**
     * Analyze plant health from an image
     *
     * @param string $imagePath Path to the plant image
     * @param array $plant Plant data for context
     * @return array|null Health analysis or null on failure
     */
    public function analyzeHealth(string $imagePath, array $plant): ?array;

    /**
     * Generate a care plan for a plant
     *
     * @param array $plant Plant data
     * @param array $careLog Recent care history
     * @param string $season Current season
     * @return array|null Care plan or null on failure
     */
    public function generateCarePlan(array $plant, array $careLog, string $season): ?array;

    /**
     * Chat with AI about a specific plant
     *
     * @param array $plant Plant data including species, conditions, etc.
     * @param array $messages Conversation history [{role: 'user'|'assistant', content: '...'}]
     * @param array $context Additional context (care log, tasks, etc.)
     * @return array Response with 'content' and optional 'suggested_actions'
     */
    public function chat(array $plant, array $messages, array $context = []): array;

    /**
     * Get the provider name
     *
     * @return string 'claude' or 'openai'
     */
    public function getProviderName(): string;

    /**
     * Validate that the API key works
     *
     * @return bool True if key is valid
     */
    public function validateApiKey(): bool;

    /**
     * Get the model name being used
     *
     * @return string Model identifier
     */
    public function getModel(): string;

    /**
     * Generate a species care info sheet
     *
     * @param string $species The species name
     * @return array|null Care info data or null on failure
     */
    public function generateSpeciesCareInfo(string $species): ?array;

    /**
     * Analyze check data for insights and recommendations
     *
     * @param array $checkData Current check data (moisture, health, observations)
     * @param array $recentChecks Previous check readings for trend analysis
     * @param array $plantInfo Plant details (species, conditions, etc.)
     * @param array $currentTasks Current scheduled care tasks
     * @return array|null Insight with type, message, and optional suggestion
     */
    public function analyzeCheckData(array $checkData, array $recentChecks, array $plantInfo, array $currentTasks): ?array;
}

/**
 * Factory to get the appropriate AI service
 */
class AIServiceFactory
{
    public const CLAUDE_MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 (Most capable)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (Balanced)',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (Fast & cheap)'
    ];

    public const OPENAI_MODELS = [
        'gpt-5.2' => 'GPT-5.2 (Most capable)',
        'gpt-4.5-preview' => 'GPT-4.5 Preview',
        'gpt-4o' => 'GPT-4o (Balanced)',
        'gpt-4o-mini' => 'GPT-4o Mini (Fast & cheap)',
        'o1' => 'o1 (Advanced reasoning)',
        'o1-mini' => 'o1 Mini (Efficient reasoning)'
    ];

    public const DEFAULT_CLAUDE_MODEL = 'claude-opus-5-5';
    public const DEFAULT_OPENAI_MODEL = 'gpt-5.2';

    /**
     * The model to use for a provider. A saved model that is no longer
     * offered (like an older Claude model) falls back to the default.
     */
    public static function resolveModel(string $provider, ?string $saved): string
    {
        if ($provider === 'openai') {
            return ($saved && isset(self::OPENAI_MODELS[$saved])) ? $saved : self::DEFAULT_OPENAI_MODEL;
        }
        return ($saved && isset(self::CLAUDE_MODELS[$saved])) ? $saved : self::DEFAULT_CLAUDE_MODEL;
    }

    /**
     * Get AI service for a user based on their settings
     *
     * @param int $userId User ID
     * @param string|null $provider Override provider ('claude' or 'openai')
     * @return AIServiceInterface
     * @throws Exception if no valid API key configured
     */
    public static function getForUser(int $userId, ?string $provider = null): AIServiceInterface
    {
        // Get user's AI settings ($stmt->fetch() returns false, not null, when no row exists)
        $stmt = db()->prepare('SELECT * FROM ai_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $settings = $stmt->fetch() ?: null;

        // Determine which provider to use
        $useProvider = $provider ?? ($settings['default_provider'] ?? 'openai');

        // Get the appropriate API key
        // Use the model the user picked in Settings (it was saved but never applied before)
        $claudeModel = self::resolveModel('claude', $settings['claude_model'] ?? null);
        $openaiModel = self::resolveModel('openai', $settings['openai_model'] ?? null);

        if ($useProvider === 'openai') {
            $apiKey = self::getUserApiKey($settings, 'openai');
            if ($apiKey) {
                return new OpenAIService($apiKey, $openaiModel);
            }
            // Fall back to Claude if no OpenAI key
            $apiKey = self::getUserApiKey($settings, 'claude');
            if ($apiKey) {
                return new ClaudeService($apiKey, $claudeModel);
            }
        } else {
            $apiKey = self::getUserApiKey($settings, 'claude');
            if ($apiKey) {
                return new ClaudeService($apiKey, $claudeModel);
            }
            // Fall back to OpenAI if no Claude key
            $apiKey = self::getUserApiKey($settings, 'openai');
            if ($apiKey) {
                return new OpenAIService($apiKey, $openaiModel);
            }
        }

        // Fall back to server-configured keys
        if (CLAUDE_API_KEY) {
            return new ClaudeService(CLAUDE_API_KEY, $claudeModel);
        }

        throw new Exception('No AI API key configured. Please add your API key in Settings.');
    }

    /**
     * Get decrypted API key for a provider
     */
    private static function getUserApiKey(?array $settings, string $provider): ?string
    {
        if (!$settings) {
            return null;
        }

        $keyField = $provider === 'openai' ? 'openai_api_key_encrypted' : 'claude_api_key_encrypted';

        if (empty($settings[$keyField])) {
            return null;
        }

        try {
            return EncryptionService::decrypt($settings[$keyField]);
        } catch (Exception $e) {
            error_log("Failed to decrypt {$provider} API key: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create an AI service with a specific API key (for key validation)
     */
    public static function createWithKey(string $provider, string $apiKey): AIServiceInterface
    {
        if ($provider === 'openai') {
            return new OpenAIService($apiKey);
        }
        return new ClaudeService($apiKey);
    }
}
