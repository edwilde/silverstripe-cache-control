<?php

/**
 * Cache Control Middleware
 *
 * HTTP middleware that applies cache control headers to responses.
 * Works by checking for a custom header set by the controller that contains
 * the desired cache control value.
 *
 * Flow:
 * 1. Request is processed by the application
 * 2. Controller sets X-Cache-Control-Override header with desired value
 * 3. Middleware intercepts response and applies the header
 * 4. Response is sent to client with Cache-Control header
 *
 * This approach ensures cache headers are only set after the page is fully rendered,
 * avoiding conflicts with other middleware or controller logic.
 *
 * @package Edwilde\CacheControls
 * @author Ed Wilde
 */

namespace Edwilde\CacheControls\Middleware;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;

/**
 * HTTP middleware for applying cache control headers
 *
 * Intercepts responses and applies cache control headers based on
 * values set by the controller via X-Cache-Control-Override header.
 */
class CacheControlMiddleware implements HTTPMiddleware
{
    /**
     * Process the HTTP request and apply cache control headers
     *
     * This method is called for every HTTP request. It:
     * 1. Allows the request to be processed by the application
     * 2. Checks for X-Cache-Control-Override header set by the controller
     * 3. Applies the cache control header to the response if present
     *
     * @param HTTPRequest $request The incoming HTTP request
     * @param callable $delegate The next middleware in the chain
     * @return HTTPResponse The processed response with cache headers applied
     */
    public function process(HTTPRequest $request, callable $delegate)
    {
        // Process the request through the rest of the middleware chain
        $response = $delegate($request);

        // Only process HTTP responses
        if (!($response instanceof HTTPResponse)) {
            return $response;
        }

        // Check if controller set a custom cache header via our special header
        $cacheHeader = $request->getHeader('X-Cache-Control-Override');
        
        // Debug logging for troubleshooting
        file_put_contents(BASE_PATH . '/cache-debug.log', sprintf(
            "[%s] Override header: %s, Response header before: %s\n",
            date('Y-m-d H:i:s'),
            $cacheHeader ?: 'NONE',
            $response->getHeader('Cache-Control') ?: 'NONE'
        ), FILE_APPEND);
        
        // Apply the cache control header if one was set
        if ($cacheHeader) {
            $response->removeHeader('Cache-Control');
            $response->addHeader('Cache-Control', $cacheHeader);
            
            // Debug: confirm what we set
            file_put_contents(BASE_PATH . '/cache-debug.log', sprintf(
                "[%s] AFTER SET: %s\n",
                date('Y-m-d H:i:s'),
                $response->getHeader('Cache-Control') ?: 'NONE'
            ), FILE_APPEND);
        }

        return $response;
    }

    /**
     * Get the current page being rendered (helper method for potential future use)
     *
     * Attempts to retrieve the current page object from the controller.
     * Returns null if page cannot be determined.
     *
     * @param HTTPRequest $request The current HTTP request
     * @return SiteTree|null The current page or null
     */
    private function getCurrentPage(HTTPRequest $request)
    {
        try {
            // Try to get current controller after request has been processed
            $controller = Controller::has_curr() ? Controller::curr() : null;
            
            if ($controller instanceof ContentController && $controller->hasMethod('data')) {
                $page = $controller->data();
                
                if ($page instanceof SiteTree && $page->exists()) {
                    return $page;
                }
            }
        } catch (\Exception $e) {
            // Controller not available
        }
        
        return null;
    }
}
