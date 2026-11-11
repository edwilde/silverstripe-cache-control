<?php

namespace Edwilde\CacheControl\Tests;

use SilverStripe\Core\Config\Config;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Sets the versioned cache control defaults config for a test, which SapphireTest restores afterwards.
 */
final class DefaultsConfig
{
    /**
     * Set the configured defaults version and field map for the current test
     *
     * @param int $version The configured defaults version
     * @param array<string, mixed> $defaults The configured field map
     * @return void
     */
    public static function set(int $version, array $defaults): void
    {
        Config::modify()->set(SiteConfig::class, 'cache_control_defaults_version', $version);
        Config::modify()->set(SiteConfig::class, 'cache_control_defaults', $defaults);
    }
}
