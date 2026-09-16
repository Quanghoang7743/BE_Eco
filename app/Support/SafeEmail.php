<?php

namespace App\Support;

use Closure;

final class SafeEmail
{
    public static function normalize(mixed $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function normalizeNullable(mixed $email): ?string
    {
        $normalized = self::normalize($email);

        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeForValidation(mixed $email): mixed
    {
        if ($email === null || preg_match('/[\r\n]/', (string) $email)) {
            return $email;
        }

        return self::normalizeNullable($email);
    }

    public static function isValid(mixed $email): bool
    {
        if (preg_match('/[\r\n]/', (string) $email)) {
            return false;
        }

        $normalized = self::normalize($email);

        return $normalized !== ''
            && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validationRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || trim((string) $value) === '') {
                return;
            }

            if (! self::isValid($value)) {
                $fail('Email khong hop le.');
            }
        };
    }
}
