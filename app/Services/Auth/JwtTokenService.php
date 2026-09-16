<?php

namespace App\Services\Auth;

use RuntimeException;

class JwtTokenService
{
    public function issue(array $claims, int $ttlSeconds = 3600): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        return $this->encode($payload);
    }

    public function decode(string $jwt): array
    {
        [$encodedHeader, $encodedPayload, $encodedSignature] = explode('.', $jwt);

        $signature = $this->base64UrlDecode($encodedSignature);
        $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret(), true);

        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid JWT signature.');
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (!is_array($payload) || !isset($payload['exp']) || time() >= (int) $payload['exp']) {
            throw new RuntimeException('JWT is expired or invalid.');
        }

        return $payload;
    }

    private function encode(array $payload): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secret(), true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature;
    }

    private function secret(): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new RuntimeException('APP_KEY is missing.');
        }

        return str_starts_with($secret, 'base64:') ? base64_decode(substr($secret, 7), true) ?: $secret : $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url value.');
        }

        return $decoded;
    }
}
