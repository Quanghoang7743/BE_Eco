<?php

namespace App\Support;

use RuntimeException;

final class ReturnEvidencePaths
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm'];
    private const ALLOWED_PREFIXES = [
        'uploads/',
        'storage/uploads/',
        'returns/',
        'storage/returns/',
        'shop_returns/',
        'storage/shop_returns/',
    ];
    private const OWNER_SCOPED_ROOTS = [
        'returns',
        'shop_returns',
    ];

    public static function sanitizeImages(?array $paths): array
    {
        if ($paths === null) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $path): string => self::sanitizePath($path, self::IMAGE_EXTENSIONS),
            $paths
        ));
    }

    public static function sanitizeVideo(mixed $path): ?string
    {
        if ($path === null || trim((string) $path) === '') {
            return null;
        }

        return self::sanitizePath($path, self::VIDEO_EXTENSIONS);
    }

    public static function sanitizeImagesForOwner(?array $paths, string|int|null $ownerId, array $roots = ['returns']): array
    {
        if ($paths === null) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $path): string => self::sanitizeOwnerPath($path, self::IMAGE_EXTENSIONS, $ownerId, $roots),
            $paths
        ));
    }

    public static function sanitizeVideoForOwner(mixed $path, string|int|null $ownerId, array $roots = ['returns']): ?string
    {
        if ($path === null || trim((string) $path) === '') {
            return null;
        }

        return self::sanitizeOwnerPath($path, self::VIDEO_EXTENSIONS, $ownerId, $roots);
    }

    private static function sanitizePath(mixed $path, array $allowedExtensions): string
    {
        $normalized = trim(str_replace('\\', '/', (string) $path));
        $normalized = ltrim($normalized, '/');

        if (
            $normalized === ''
            || preg_match('/[\x00-\x1F\x7F<>"\']/', $normalized)
            || preg_match('#(^|/)\.\.(/|$)#', $normalized)
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $normalized)
            || str_starts_with($normalized, '//')
        ) {
            throw new RuntimeException('INVALID_EVIDENCE_PATH');
        }

        $hasAllowedPrefix = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $hasAllowedPrefix = true;
                break;
            }
        }

        if (! $hasAllowedPrefix) {
            throw new RuntimeException('INVALID_EVIDENCE_PATH');
        }

        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('INVALID_EVIDENCE_EXTENSION');
        }

        return $normalized;
    }

    private static function sanitizeOwnerPath(mixed $path, array $allowedExtensions, string|int|null $ownerId, array $roots): string
    {
        $normalized = self::sanitizePath($path, $allowedExtensions);
        $owner = self::normalizeOwnerId($ownerId);
        $prefixes = self::ownerPrefixes($owner, $roots);

        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return $normalized;
            }
        }

        throw new RuntimeException('EVIDENCE_OWNER_MISMATCH');
    }

    private static function normalizeOwnerId(string|int|null $ownerId): string
    {
        $owner = trim((string) $ownerId);
        if ($owner === '' || preg_match('/[^A-Za-z0-9._-]/', $owner)) {
            throw new RuntimeException('INVALID_EVIDENCE_OWNER');
        }

        return $owner;
    }

    private static function ownerPrefixes(string $ownerId, array $roots): array
    {
        $prefixes = [];
        foreach ($roots as $root) {
            $root = trim(str_replace('\\', '/', (string) $root), '/');
            if (! in_array($root, self::OWNER_SCOPED_ROOTS, true)) {
                throw new RuntimeException('INVALID_EVIDENCE_ROOT');
            }

            $prefixes[] = 'uploads/' . $root . '/' . $ownerId . '/';
            $prefixes[] = 'storage/uploads/' . $root . '/' . $ownerId . '/';
            $prefixes[] = $root . '/' . $ownerId . '/';
            $prefixes[] = 'storage/' . $root . '/' . $ownerId . '/';
        }

        return $prefixes;
    }
}
