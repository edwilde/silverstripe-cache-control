<?php

namespace Edwilde\CacheControls\Middleware;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;

class CacheControlMiddleware implements HTTPMiddleware
{
    public function process(HTTPRequest $request, callable $delegate)
    {
        $response = $delegate($request);

        if (!($response instanceof HTTPResponse)) {
            return $response;
        }

        // Check if controller set a custom cache header
        $cacheHeader = $request->getHeader('X-Cache-Control-Override');
        
        // Debug logging
        file_put_contents(BASE_PATH . '/cache-debug.log', sprintf(
            "[%s] Override header: %s, Response header before: %s\n",
            date('Y-m-d H:i:s'),
            $cacheHeader ?: 'NONE',
            $response->getHeader('Cache-Control') ?: 'NONE'
        ), FILE_APPEND);
        
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
