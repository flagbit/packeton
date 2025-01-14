<?php

declare(strict_types=1);

namespace Packeton\Package;

use Packeton\Form\Type\MonoRepoPackageType;
use Packeton\Form\Type\PackageType;

class RepTypes
{
    public const VCS = 'vcs';
    public const MONO_REPO = 'mono-repo';
    public const ARTIFACT = 'artifact';

    private static $types = [
        self::ARTIFACT,
        self::MONO_REPO,
        self::VCS,
    ];

    public static function getFormType(?string $type): string
    {
        return match ($type) {
            self::MONO_REPO => MonoRepoPackageType::class,
            default => PackageType::class,
        };
    }

    public static function normalizeType(?string $type): string
    {
        return match ($type) {
            self::MONO_REPO => self::MONO_REPO,
            default => self::VCS,
        };
    }

    public static function getUITemplate(?string $type): string
    {
        return match ($type) {
            self::MONO_REPO => MonoRepoPackageType::class,
            default => PackageType::class,
        };
    }
}
