<?php
declare(strict_types=1);

namespace Lumui\Protocol;

use RuntimeException;

final class SpecificationResources
{
    public static function root(): string
    {
        $configuredRoot = getenv('LUMUI_SPECIFICATION_ROOT');
        $candidates = [];
        if (is_string($configuredRoot) && trim($configuredRoot) !== '') {
            $configuredRoot = rtrim($configuredRoot, '/\\');
            $candidates[] = is_dir($configuredRoot . '/resources')
                ? $configuredRoot . '/resources'
                : $configuredRoot;
        }
        $candidates[] = dirname(__DIR__, 3) . '/lumui/resources';
        $candidates[] = dirname(__DIR__, 4) . '/lumui/Source/resources';

        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/component-catalog.json')
                && is_file($candidate . '/schemas/surface.schema.json')
            ) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'The LUMUI specification resources could not be located.'
        );
    }

    public static function file(string $relativePath): string
    {
        return self::root() . '/' . ltrim($relativePath, '/\\');
    }
}
