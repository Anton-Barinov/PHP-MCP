<?php

declare(strict_types=1);

namespace PhpMcp\Protocol;

final class ProtocolVersion
{
    public const LATEST = '2024-11-05';
    public const V2024_11_05 = '2024-11-05';
    public const V2024_10_07 = '2024-10-07';

    public const SUPPORTED = [
        self::V2024_11_05,
        self::V2024_10_07,
    ];

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }
}
