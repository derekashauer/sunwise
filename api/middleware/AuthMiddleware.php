<?php
/**
 * JWT Authentication Middleware
 */

class AuthMiddleware
{
    /**
     * Validate JWT token and return user ID
     */
    public function validateToken(string $token): ?int
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            list($header, $payload, $signature) = $parts;

            // Verify signature
            $expectedSignature = $this->base64UrlEncode(
                hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
            );

            if (!hash_equals($expectedSignature, $signature)) {
                return null;
            }

            // Decode payload
            $payloadData = json_decode($this->base64UrlDecode($payload), true);

            if (!$payloadData) {
                return null;
            }

            // Check expiration
            if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
                return null;
            }

            return $payloadData['sub'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generate JWT token for user
     */
    public function generateToken(int $userId): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]));

        $payload = $this->base64UrlEncode(json_encode([
            'sub' => $userId,
            'iat' => time(),
            'exp' => time() + JWT_EXPIRY
        ]));

        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
        );

        return "$header.$payload.$signature";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
