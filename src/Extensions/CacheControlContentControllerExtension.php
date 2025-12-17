<?php

/**
 * Cache Control Content Controller Extension
 *
 * Extends ContentController to intercept page rendering and apply cache control headers.
 * This is where the configured cache settings are actually translated into HTTP headers.
 *
 * Flow:
 * 1. Page is rendered via ContentController
 * 2. afterCallActionHandler is triggered
 * 3. Extension retrieves cache header from page (which may be page-specific or site-wide)
 * 4. Extension applies cache header using SilverStripe's HTTPCacheControlMiddleware
 *
 * This approach ensures cache headers are set at the right time in the request lifecycle,
 * after the page content is ready but before the response is sent.
 *
 * @package Edwilde\CacheControls
 * @author Ed Wilde
 */

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\Control\HTTP;
use SilverStripe\Core\Extension;

/**
 * Controller extension for applying cache control headers
 *
 * Hooks into the controller's action handler to apply configured
 * cache control headers to the response.
 */
class CacheControlContentControllerExtension extends Extension
{
    /**
     * Hook after action handler to apply cache control headers
     *
     * This method is called after the controller action has been executed
     * but before the response is sent. It retrieves the cache control header
     * from the page and applies it.
     *
     * @param HTTPRequest $request The current request
     * @param string $action The action that was called
     * @param mixed $result The result of the action
     * @return void
     */
    public function afterCallActionHandler($request, $action, $result)
    {
        $page = $this->owner->data();
        
        // Check if page has cache control capabilities
        if ($page && $page->hasMethod('getCacheControlHeader')) {
            $cacheHeader = $page->getCacheControlHeader();
            
            if ($cacheHeader) {
                // Apply the cache control header to the response
                $this->applyCacheControl($cacheHeader);
            }
        }
    }
    
    /**
     * Parse and apply cache control header
     *
     * Takes a cache control header string (e.g., "public, max-age=120")
     * and applies it using SilverStripe's HTTPCacheControlMiddleware.
     *
     * Handles:
     * - public/private cache directives
     * - max-age values
     * - must-revalidate directive
     * - no-store directive
     * - Expires header (set to match max-age per HTTP spec)
     *
     * @param string $header The cache control header value to apply
     * @return void
     */
    private function applyCacheControl($header)
    {
        // Get the HTTPCacheControlMiddleware singleton to configure cache behavior
        $cacheControl = \SilverStripe\Control\Middleware\HTTPCacheControlMiddleware::singleton();
        
        // Handle no-store directive FIRST (prevents all caching)
        if (strpos($header, 'no-store') !== false) {
            $cacheControl->disableCache(true);
            return;
        }
        
        // Extract max-age value from header string
        $maxAge = 0;
        if (preg_match('/max-age=(\d+)/', $header, $matches)) {
            $maxAge = (int)$matches[1];
        }
        
        // Apply private or public cache directive
        if (strpos($header, 'private') !== false) {
            // For private cache, we need to manually configure it
            // because privateCache() doesn't accept maxAge parameter
            $cacheControl->privateCache(true);
            if ($maxAge > 0) {
                $cacheControl->setMaxAge($maxAge);
                // Add Expires header to match max-age (HTTP/1.0 compatibility)
                $this->setExpiresHeader($maxAge);
            }
        } else {
            // For public cache, we can pass maxAge directly
            $cacheControl->publicCache(true, $maxAge);
            if ($maxAge > 0) {
                // Add Expires header to match max-age (HTTP/1.0 compatibility)
                $this->setExpiresHeader($maxAge);
            }
        }
        
        // Handle must-revalidate directive
        if (strpos($header, 'must-revalidate') === false) {
            // Remove if not in our header
            $cacheControl->removeStateDirective('public', 'must-revalidate');
            $cacheControl->removeStateDirective('private', 'must-revalidate');
        } else {
            // Add if present in our header
            $cacheControl->setStateDirective('public', 'must-revalidate', true);
            $cacheControl->setStateDirective('private', 'must-revalidate', true);
        }
    }
    
    /**
     * Set the Expires header based on max-age value
     *
     * The Expires header provides HTTP/1.0 compatibility and should match
     * the Cache-Control max-age directive. It's calculated as the current
     * time plus the max-age value.
     *
     * @param int $maxAge The max-age value in seconds
     * @return void
     */
    private function setExpiresHeader($maxAge)
    {
        $response = $this->owner->getResponse();
        if ($response) {
            $expiresTime = time() + $maxAge;
            $response->addHeader('Expires', gmdate('D, d M Y H:i:s', $expiresTime) . ' GMT');
        }
    }
}
