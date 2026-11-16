<?php
/**
 * Push Notification Service
 *
 * Sends Web Push messages with VAPID authentication (RFC 8292). Messages
 * carry no payload, which keeps this free of payload encryption: the service
 * worker shows a fixed "Your plants need care" notification when one
 * arrives. That's all the daily reminder needs.
 *
 * Keys: VAPID_PUBLIC_KEY is the base64url uncompressed P-256 point (65 bytes)
 * and VAPID_PRIVATE_KEY the base64url private scalar (32 bytes), the format
 * most VAPID key generators produce.
 */

class PushService
{
    /**
     * Send a reminder to a user's saved subscription.
     */
    public function sendToUser(int $userId): bool
    {
        $stmt = db()->prepare('SELECT push_subscription FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $json = $stmt->fetchColumn();
        if (!$json) {
            return false;
        }

        $subscription = json_decode($json, true);
        if (!is_array($subscription) || empty($subscription['endpoint'])) {
            return false;
        }

        $status = $this->send($subscription);

        // 404/410: the browser dropped this subscription, so forget it
        if ($status === 404 || $status === 410) {
            db()->prepare('UPDATE users SET push_subscription = NULL WHERE id = ?')->execute([$userId]);
        }

        return $status >= 200 && $status < 300;
    }

    /**
     * POST an empty push message. Returns the push service's HTTP status.
     */
    public function send(array $subscription): int
    {
        if (!self::isConfigured()) {
            error_log('Push skipped: VAPID keys are not configured');
            return 0;
        }

        $endpoint = $subscription['endpoint'];
        $jwt = self::vapidJwt($endpoint);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY,
                'TTL: 43200',
                'Urgency: normal',
                'Content-Length: 0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Push request failed: ' . $error);
        }

        return $status;
    }

    public static function isConfigured(): bool
    {
        return VAPID_PUBLIC_KEY !== '' && VAPID_PRIVATE_KEY !== '';
    }

    /**
     * Signed VAPID JWT for a push endpoint's origin, valid for 12 hours.
     */
    public static function vapidJwt(string $endpoint, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $now = $now ?? time();

        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => $now + 43200,
            'sub' => 'mailto:' . MAIL_FROM,
        ]));
        $signingInput = $header . '.' . $claims;

        $derSignature = '';
        if (!openssl_sign($signingInput, $derSignature, self::privateKeyPem(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token. Check VAPID_PRIVATE_KEY.');
        }

        return $signingInput . '.' . self::base64UrlEncode(self::derToRawSignature($derSignature));
    }

    /**
     * Build a PEM private key from the raw base64url VAPID keys.
     */
    public static function privateKeyPem(?string $publicKey = null, ?string $privateKey = null): string
    {
        $public = self::base64UrlDecode($publicKey ?? VAPID_PUBLIC_KEY);
        $private = self::base64UrlDecode($privateKey ?? VAPID_PRIVATE_KEY);
        if (strlen($public) !== 65 || strlen($private) !== 32) {
            throw new RuntimeException('VAPID keys are not in the expected format');
        }

        // SEC1 ECPrivateKey for prime256v1
        $der = "\x30\x77"
            . "\x02\x01\x01"
            . "\x04\x20" . $private
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . $public;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * OpenSSL returns a DER ECDSA signature; JWT needs raw r||s (64 bytes).
     */
    public static function derToRawSignature(string $der): string
    {
        $offset = 2; // SEQUENCE tag + length
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7f;
        }
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $int = ltrim($int, "\x00");
            $raw .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $len;
        }
        return $raw;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        return (string) base64_decode($data . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
