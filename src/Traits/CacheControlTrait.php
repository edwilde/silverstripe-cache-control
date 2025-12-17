<?php

/**
 * Cache Control Trait
 *
 * Provides shared functionality for building cache control headers.
 * This trait was part of the original design but is currently unused as
 * the logic has been moved directly into the extensions.
 *
 * NOTE: This trait is kept for potential future refactoring where shared
 * logic between SiteConfigExtension and PageExtension could be extracted.
 * Currently both extensions implement their own getCacheControlHeader() methods
 * with identical logic.
 *
 * @package Edwilde\CacheControls
 * @author Ed Wilde
 * @deprecated Not currently used - logic in extensions instead
 */

namespace Edwilde\CacheControls\Traits;

/**
 * Shared cache control header building logic
 *
 * Provides a reusable method for constructing cache control headers
 * from configuration properties.
 */
trait CacheControlTrait
{
    /**
     * Build a cache control header from configuration properties
     *
     * Constructs the cache control header value based on:
     * - EnableCacheControl: whether caching is enabled
     * - EnableNoStore: whether to prevent all caching
     * - CacheType: public or private caching
     * - EnableMaxAge: whether to set max-age
     * - MaxAge: the max-age value in seconds
     * - EnableMustRevalidate: whether to require revalidation
     *
     * @return string|null The constructed cache control header or null if disabled
     */
    protected function buildCacheControlHeader()
    {
        if (!$this->EnableCacheControl) {
            return null;
        }

        $directives = [];

        // no-store takes precedence over everything
        if ($this->EnableNoStore) {
            $directives[] = 'no-store';
        }

        // Add cache type (public/private)
        if ($this->CacheType) {
            $directives[] = $this->CacheType;
        }

        // Only add max-age if no-store is not enabled
        if ($this->EnableMaxAge && !$this->EnableNoStore) {
            $maxAge = (int)$this->MaxAge ?: 120;
            $directives[] = 'max-age=' . $maxAge;
        }

        // Add must-revalidate directive
        if ($this->EnableMustRevalidate) {
            $directives[] = 'must-revalidate';
        }

        return !empty($directives) ? implode(', ', $directives) : null;
    }
}
