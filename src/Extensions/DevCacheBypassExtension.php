<?php

/**
 * Dev Cache Bypass Extension
 *
 * Allows cache headers to be applied in dev mode when CACHE_HEADERS_IN_DEV environment
 * variable is set to true. This is useful for testing cache behavior without switching
 * to test/live mode.
 *
 * When enabled:
 * - Cache headers are applied even in dev mode
 * - Same rules for private/form pages still apply
 * - Pages that shouldn't be cached won't be cached
 *
 * Usage:
 * Set in your .env file:
 * CACHE_HEADERS_IN_DEV="true"
 *
 * @package Edwilde\CacheControls
 * @author Ed Wilde
 */

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Extension to bypass dev mode cache header suppression
 *
 * Works alongside the nswdpc ContentControllerExtension to allow cache headers
 * to be output in dev mode for testing purposes when the environment variable is set.
 */
class DevCacheBypassExtension extends Extension
{
    /**
     * Runs before nswdpc's ContentControllerExtension::onAfterInit
     *
     * In dev mode with bypass enabled, this applies cache rules
     * that would normally only apply on LIVE stage.
     *
     * @return void
     */
    public function onAfterInit()
    {
        // Only apply our bypass logic if enabled
        if (!$this->shouldBypassDevMode()) {
            return;
        }

        // Apply the same restricted record cache state logic
        // that nswdpc normally only applies on LIVE stage
        $this->applyRestrictedRecordCacheState();
    }

    /**
     * Check if we should bypass dev mode restrictions
     *
     * @return bool True if bypass is enabled and we're in dev mode
     */
    protected function shouldBypassDevMode(): bool
    {
        // Only bypass if explicitly enabled via environment variable
        $enabled = Environment::getEnv('CACHE_HEADERS_IN_DEV');
        if (!$enabled || $enabled === 'false' || $enabled === '0') {
            return false;
        }

        // Only bypass in dev mode (not test or live)
        $environment = Environment::getEnv('SS_ENVIRONMENT_TYPE');
        return $environment === 'dev';
    }

    /**
     * Apply cache state based on record restrictions
     *
     * This is a simplified version of nswdpc's logic that works in dev mode.
     *
     * @return void
     */
    protected function applyRestrictedRecordCacheState()
    {
        $record = $this->owner->data();
        if (!$record || !($record instanceof SiteTree)) {
            return $this->setDisableCacheState();
        }

        $siteConfig = $record->getSiteConfig();
        if (!$siteConfig || !($siteConfig instanceof SiteConfig)) {
            return $this->setDisableCacheState();
        }

        if ($siteConfig->CanViewType !== InheritedPermissions::ANYONE) {
            return $this->setDisableCacheState();
        } elseif (!$this->hasAnyoneViewPermission($record, $siteConfig)) {
            return $this->setDisableCacheState();
        }
    }

    /**
     * Disable the cache state for restricted pages
     *
     * @return void
     */
    protected function setDisableCacheState()
    {
        HTTPCacheControlMiddleware::singleton()->disableCache(true);
    }

    /**
     * Check if a record can be viewed by anyone
     *
     * @param SiteTree $record The page record
     * @param SiteConfig $siteConfig The site configuration
     * @return bool True if anyone can view
     */
    protected function hasAnyoneViewPermission(SiteTree $record, SiteConfig $siteConfig): bool
    {
        if ($record->CanViewType === InheritedPermissions::ANYONE) {
            return true;
        } elseif ($record->CanViewType === InheritedPermissions::INHERIT) {
            if (($parent = $record->Parent()) && $parent->exists()) {
                return $this->hasAnyoneViewPermission($parent, $siteConfig);
            } else {
                return $siteConfig->CanViewType === InheritedPermissions::ANYONE;
            }
        } else {
            return false;
        }
    }
}
