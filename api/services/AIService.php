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
        // Get user's AI settings
        $stmt = db()->prepare('SELECT * FROM ai_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $settings = $stmt->fetch();

        // Determine which provider to use
        $useProvider = $provider ?? ($settings['default_provider'] ?? 'openai');

        // Get the appropriate API key
        if ($useProvider === 'openai') {
            $apiKey = self::getUserApiKey($settings, 'openai');
            if ($apiKey) {
                return new OpenAIService($apiKey);
            }
            // Fall back to Claude if no OpenAI key
            $apiKey = self::getUserApiKey($settings, 'claude');
            if ($apiKey) {
                return new ClaudeService($apiKey);
            }
        } else {
            $apiKey = self::getUserApiKey($settings, 'claude');
            if ($apiKey) {
                return new ClaudeService($apiKey);
            }
            // Fall back to OpenAI if no Claude key
            $apiKey = self::getUserApiKey($settings, 'openai');
            if ($apiKey) {
                return new OpenAIService($apiKey);
            }
        }

        // Fall back to server-configured keys
        if (CLAUDE_API_KEY) {
            return new ClaudeService(CLAUDE_API_KEY);
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
