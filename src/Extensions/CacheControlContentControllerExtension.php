<?php

/**
 * Cache Control Content Controller Extension
 *
 * Hooks into ContentController to apply CMS-configured cache control settings
 * using the nswdpc/silverstripe-cache-headers middleware.
 *
 * This extension bridges between our CMS UI (configured via SiteConfig and Page extensions)
 * and the underlying cache header middleware provided by nswdpc.
 *
 * @package Edwilde\CacheControl
 * @author Ed Wilde
 */

namespace Edwilde\CacheControl\Extensions;

use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Extension;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\CMS\Model\SiteTree;

/**
 * Content Controller extension for applying cache control
 *
 * Applies cache control headers based on CMS configuration at either
 * the page level (if overridden) or site level (default).
 */
class CacheControlContentControllerExtension extends Extension
{
    /**
     * Apply cache control settings after controller initialization
     *
     * Determines whether to use page-level or site-level cache control settings
     * and applies them to the HTTP response via the middleware.
     *
     * @return void
     */
    public function onAfterInit()
    {
        $page = $this->owner->data();
        if (!$page || !($page instanceof SiteTree)) {
            return;
        }

        // Check if page has override enabled
        if ($page->OverrideCacheControl && $page->hasExtension(CacheControlPageExtension::class)) {
            $this->applyPageSettings($page);
        } else {
            $this->applySiteSettings();
        }
    }

    /**
     * Apply cache control settings from the current page
     *
     * @param SiteTree $page The current page
     * @return void
     */
    protected function applyPageSettings(SiteTree $page)
    {
        $middleware = HTTPCacheControlMiddleware::singleton();

        if (!$page->EnableCacheControl) {
            // Cache control disabled for this page - use private, no-store
            $middleware->disableCache(true);
            return;
        }

        // Set cache state (public/private)
        if ($page->CacheType === 'public') {
            $middleware->publicCache();
        } else {
            $middleware->privateCache();
        }

        // Handle cache duration
        if ($page->CacheDuration === 'nostore') {
            $middleware->setNoStore(true);
        } else {
            // max-age enabled
            $maxAge = $this->getMaxAgeValue($page->MaxAgePreset, $page->MaxAge);
            $middleware->setMaxAge($maxAge);

            // Add Expires header to match max-age
            $this->setExpiresHeader($maxAge);

            // Must revalidate
            if ($page->EnableMustRevalidate) {
                $middleware->setMustRevalidate(true);
            }
        }

        // Apply Vary headers
        $this->applyVaryHeaders($page);
    }

    /**
     * Apply cache control settings from site config
     *
     * @return void
     */
    protected function applySiteSettings()
    {
        $siteConfig = SiteConfig::current_site_config();
        if (!$siteConfig || !$siteConfig->hasExtension(CacheControlSiteConfigExtension::class)) {
            return;
        }

        $middleware = HTTPCacheControlMiddleware::singleton();

        if (!$siteConfig->EnableCacheControl) {
            // Cache control disabled site-wide - use private, no-store
            $middleware->disableCache(true);
            return;
        }

        // Set cache state (public/private)
        if ($siteConfig->CacheType === 'public') {
            $middleware->publicCache();
        } else {
            $middleware->privateCache();
        }

        // Handle cache duration
        if ($siteConfig->CacheDuration === 'nostore') {
            $middleware->setNoStore(true);
        } else {
            // max-age enabled
            $maxAge = $this->getMaxAgeValue($siteConfig->MaxAgePreset, $siteConfig->MaxAge);
            $middleware->setMaxAge($maxAge);

            // Add Expires header to match max-age
            $this->setExpiresHeader($maxAge);

            // Must revalidate
            if ($siteConfig->EnableMustRevalidate) {
                $middleware->setMustRevalidate(true);
            }
        }

        // Apply Vary headers
        $this->applyVaryHeaders($siteConfig);
    }

    /**
     * Get the max-age value based on preset or custom value
     *
     * @param string $preset The preset value
     * @param int $customValue The custom max-age value
     * @return int The max-age in seconds
     */
    protected function getMaxAgeValue($preset, $customValue)
    {
        if ($preset === 'custom') {
            return (int)$customValue ?: 120;
        }
        return (int)$preset;
    }

    /**
     * Set the Expires header to match the max-age
     *
     * @param int $maxAge Max age in seconds
     * @return void
     */
    protected function setExpiresHeader($maxAge)
    {
        $response = $this->owner->getResponse();
        if ($response) {
            $expires = gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT';
            $response->addHeader('Expires', $expires);
        }
    }

    /**
     * Apply Vary headers based on CMS configuration
     *
     * @param SiteTree|SiteConfig $config The configuration object
     * @return void
     */
    protected function applyVaryHeaders($config)
    {
        $varyHeaders = [];

        if ($config->VaryAcceptEncoding) {
            $varyHeaders[] = 'Accept-Encoding';
        }

        if ($config->VaryXForwardedProtocol) {
            $varyHeaders[] = 'X-Forwarded-Protocol';
        }

        if ($config->VaryCookie) {
            $varyHeaders[] = 'Cookie';
        }

        if ($config->VaryAuthorization) {
            $varyHeaders[] = 'Authorization';
        }

        if (!empty($varyHeaders)) {
            $middleware = HTTPCacheControlMiddleware::singleton();
            $middleware->setVary(implode(', ', $varyHeaders));
        }
    }
}
