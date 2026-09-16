<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

class StoreMedia
{
    public static function urls(mixed $value): array
    {
        $urls = self::collectUrls($value);
        $unique = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            $unique[$url] = $url;
        }

        return array_values($unique);
    }

    public static function primaryUrl(mixed $value): ?string
    {
        $urls = self::urls($value);

        return $urls[0] ?? null;
    }

    public static function encodeForStorage(mixed $value): ?string
    {
        $urls = self::urls($value);

        if (empty($urls)) {
            return null;
        }

        if (count($urls) === 1) {
            return $urls[0];
        }

        return json_encode(array_values($urls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function validateUrlList(mixed $value, int $maxLength = 2000): array
    {
        $urls = self::urls($value);
        $errors = [];

        foreach ($urls as $index => $url) {
            $validator = Validator::make(
                ['url' => $url],
                ['url' => ['url', 'max:' . $maxLength]]
            );

            if ($validator->fails()) {
                $errors[] = 'URL anh tai vi tri ' . ($index + 1) . ' khong hop le.';
            }
        }

        return $errors;
    }

    private static function collectUrls(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value) || is_numeric($value)) {
            $text = trim((string) $value);
            if ($text === '') {
                return [];
            }

            if (str_starts_with($text, '[') || str_starts_with($text, '{')) {
                $decoded = json_decode($text, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return self::collectUrls($decoded);
                }
            }

            return [$text];
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return [];
        }

        foreach (['url', 'image_url', 'banner_url', 'cover_image', 'path'] as $key) {
            if (array_key_exists($key, $value)) {
                return self::collectUrls($value[$key]);
            }
        }

        $urls = [];
        foreach ($value as $item) {
            $urls = array_merge($urls, self::collectUrls($item));
        }

        return $urls;
    }
}
