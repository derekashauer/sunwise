<?php
/**
 * Deferred
 *
 * Work that should run after the response has been sent, such as photo
 * health analysis. The router flushes the response first when the server
 * supports it (PHP-FPM or LiteSpeed), so the person isn't kept waiting.
 */

class Deferred
{
    /** @var callable[] */
    private static array $jobs = [];

    public static function add(callable $job): void
    {
        self::$jobs[] = $job;
    }

    public static function run(): void
    {
        $jobs = self::$jobs;
        self::$jobs = [];
        foreach ($jobs as $job) {
            try {
                $job();
            } catch (Throwable $e) {
                error_log('Deferred job failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Close the connection to the browser so deferred work runs in the background.
     */
    public static function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }
    }
}
