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

use Edwilde\CacheControl\SharedMaxAge;
use Edwilde\CacheControl\StaleDirectives;
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

        // Resolve effective cache settings from page override, ancestor, or site config
        if ($page->OverrideCacheControl && $page->hasExtension(CacheControlPageExtension::class)) {
            $this->applyPageSettings($page);
        } elseif ($page->hasExtension(CacheControlPageExtension::class)
            && ($ancestor = $page->findInheritedCacheSource())
        ) {
            $this->applyPageSettings($ancestor);
        } else {
            $this->applySiteSettings();
        }

        // After resolving effective settings, reduce max-age if page has pending draft changes
        $this->applyDraftCacheReduction($page);
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

            $this->applyStaleDirectives($middleware, $page);
            $this->applySharedMaxAge($middleware, $page);

            // must-revalidate is on by default in every cacheable state, so a grace period
            // clears it explicitly.
            if (StaleDirectives::hasGracePeriod($page)) {
                $middleware->setMustRevalidate(false);
            } elseif ($page->EnableMustRevalidate) {
                $middleware->setMustRevalidate(true);
            }
        }

        // Apply Vary headers from site config (Vary is always site-wide, not per-page)
        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig && $siteConfig->hasExtension(CacheControlSiteConfigExtension::class)) {
            $this->applyVaryHeaders($siteConfig);
        }
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

            $this->applyStaleDirectives($middleware, $siteConfig);
            $this->applySharedMaxAge($middleware, $siteConfig);

            // must-revalidate is on by default in every cacheable state, so a grace period
            // clears it explicitly.
            if (StaleDirectives::hasGracePeriod($siteConfig)) {
                $middleware->setMustRevalidate(false);
            } elseif ($siteConfig->EnableMustRevalidate) {
                $middleware->setMustRevalidate(true);
            }
        }

        // Apply Vary headers
        $this->applyVaryHeaders($siteConfig);
    }

    /**
     * Reduce cache max-age when a page has unpublished draft changes.
     *
     * The HasPendingDraftChanges flag is set at save time (onAfterWrite) and
     * cleared at publish time (onAfterPublish), so this check reads a field
     * already loaded in the page object — zero additional database queries.
     *
     * @param SiteTree $page The current page
     */
    protected function applyDraftCacheReduction(SiteTree $page)
    {
        // Check SiteConfig toggle
        $siteConfig = SiteConfig::current_site_config();
        if (!$siteConfig || !$siteConfig->EnableDraftCacheReduction) {
            return;
        }

        // Skip if cache is already disabled — nothing to reduce
        $middleware = HTTPCacheControlMiddleware::singleton();
        if ($middleware->getState() === HTTPCacheControlMiddleware::STATE_DISABLED) {
            return;
        }

        // Check the save-time flag — no DB query needed, already in the page object
        if (!$page->HasPendingDraftChanges) {
            return;
        }

        // Override max-age to the configured draft value
        $draftMaxAge = (int)$page->config()->get('draft_cache_max_age');
        if ($draftMaxAge < 1) {
            $draftMaxAge = 10; // safety fallback
        }

        $middleware->setMaxAge($draftMaxAge);
        $this->setExpiresHeader($draftMaxAge);

        // Cap an existing CDN cache duration to the same draft value, public state only.
        if ($middleware->getStateDirective(HTTPCacheControlMiddleware::STATE_PUBLIC, 's-maxage')) {
            $middleware->setStateDirective(
                [HTTPCacheControlMiddleware::STATE_PUBLIC],
                's-maxage',
                $draftMaxAge
            );
        }
    }

    /**
     * Apply the RFC 5861 grace-period directives from the resolved cache settings.
     *
     * Set on the same three states as max-age so the directives survive a later downgrade from
     * public to private. A grace period of 0 is passed as false, which removes the directive;
     * the value 0 would be emitted as "stale-while-revalidate=0".
     *
     * @param HTTPCacheControlMiddleware $middleware The middleware singleton
     * @param SiteTree|SiteConfig $source The object supplying the cache settings
     * @return void
     */
    protected function applyStaleDirectives(HTTPCacheControlMiddleware $middleware, SiteConfig|SiteTree $source): void
    {
        $states = [
            HTTPCacheControlMiddleware::STATE_ENABLED,
            HTTPCacheControlMiddleware::STATE_PRIVATE,
            HTTPCacheControlMiddleware::STATE_PUBLIC,
        ];

        foreach (StaleDirectives::resolveAll($source) as $directive => $seconds) {
            $middleware->setStateDirective($states, $directive, $seconds > 0 ? $seconds : false);
        }
    }

    /**
     * Apply the resolved CDN cache duration to the public state only.
     *
     * Set only on STATE_PUBLIC, not via setSharedMaxAge(), so a session downgrade to private
     * never emits "private, s-maxage=...". A resolved value of 0 is passed as false on every
     * non-disabled state, removing any s-maxage set elsewhere and keeping the preview honest.
     *
     * @param HTTPCacheControlMiddleware $middleware The middleware singleton
     * @param SiteTree|SiteConfig $source The object supplying the cache settings
     * @return void
     */
    protected function applySharedMaxAge(HTTPCacheControlMiddleware $middleware, SiteConfig|SiteTree $source): void
    {
        $seconds = SharedMaxAge::forSource($source);

        $middleware->setStateDirective(
            [HTTPCacheControlMiddleware::STATE_ENABLED, HTTPCacheControlMiddleware::STATE_PRIVATE],
            's-maxage',
            false
        );
        $middleware->setStateDirective(
            [HTTPCacheControlMiddleware::STATE_PUBLIC],
            's-maxage',
            $seconds > 0 ? $seconds : false
        );
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
            return (int)$customValue > 0 ? (int)$customValue : 120;
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

        // Always set Vary explicitly — even when no options are selected. The
        // framework's HTTPCacheControlMiddleware ships a defaultVary of
        // X-Forwarded-Protocol, which getVary() falls back to whenever vary has
        // never been set. Skipping setVary() when $varyHeaders is empty would
        // leak that default, so the CMS "unchecked" state would still emit
        // Vary: X-Forwarded-Protocol. Passing '' clears the default.
        $middleware = HTTPCacheControlMiddleware::singleton();
        $middleware->setVary(implode(', ', $varyHeaders));
    }
}
