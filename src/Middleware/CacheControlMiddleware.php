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

        $page = $this->getCurrentPage();
        
        if (!$page || !$page->hasMethod('getCacheControlHeader')) {
            return $response;
        }

        $cacheHeader = $page->getCacheControlHeader();
        
        if ($cacheHeader) {
            $response->removeHeader('Cache-Control');
            $response->addHeader('Cache-Control', $cacheHeader);
        }

        return $response;
    }

    private function getCurrentPage()
    {
        try {
            $controller = Controller::curr();
            
            if ($controller instanceof ContentController && $controller->hasMethod('data')) {
                $page = $controller->data();
                
                if ($page instanceof SiteTree && $page->exists()) {
                    return $page;
                }
            }
        } catch (\Exception $e) {
            // Controller not yet initialized or not a content page
        }
        
        return null;
    }
}
