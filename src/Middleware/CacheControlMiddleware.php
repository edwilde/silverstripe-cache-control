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
        
        if ($cacheHeader) {
            $response->removeHeader('Cache-Control');
            $response->addHeader('Cache-Control', $cacheHeader);
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
