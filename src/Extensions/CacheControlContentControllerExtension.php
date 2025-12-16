<?php

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\Control\HTTP;
use SilverStripe\Core\Extension;

class CacheControlContentControllerExtension extends Extension
{
    public function afterCallActionHandler($request, $action, $result)
    {
        $page = $this->owner->data();
        
        if ($page && $page->hasMethod('getCacheControlHeader')) {
            $cacheHeader = $page->getCacheControlHeader();
            
            if ($cacheHeader) {
                // Parse the cache header and apply using HTTP class
                $this->applyCacheControl($cacheHeader);
            }
        }
    }
    
    private function applyCacheControl($header)
    {
        // Parse the header to get max-age
        $maxAge = 0;
        if (preg_match('/max-age=(\d+)/', $header, $matches)) {
            $maxAge = (int)$matches[1];
        }
        
        // Get the singleton and FORCE our settings
        $cacheControl = \SilverStripe\Control\Middleware\HTTPCacheControlMiddleware::singleton();
        
        if (strpos($header, 'private') !== false) {
            $cacheControl->privateCache(true); // Force private
        } else {
            $cacheControl->publicCache(true, $maxAge); // Force public with max-age
        }
        
        // Remove must-revalidate if not in our header
        if (strpos($header, 'must-revalidate') === false) {
            $cacheControl->removeStateDirective('public', 'must-revalidate');
            $cacheControl->removeStateDirective('private', 'must-revalidate');
        } else {
            $cacheControl->setStateDirective('public', 'must-revalidate', true);
        }
        
        // Handle no-store
        if (strpos($header, 'no-store') !== false) {
            $cacheControl->setStateDirective('disabled', 'no-store', true);
        }
    }
}
