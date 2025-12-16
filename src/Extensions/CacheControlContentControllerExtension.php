<?php

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\Control\HTTP;
use SilverStripe\Core\Extension;

class CacheControlContentControllerExtension extends Extension
{
    public function onAfterInit()
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
        
        // Use HTTPCacheControlMiddleware to enable caching
        $cacheControl = \SilverStripe\Control\Middleware\HTTPCacheControlMiddleware::singleton();
        
        if (strpos($header, 'private') !== false) {
            $cacheControl->privateCache();
        } else {
            $cacheControl->publicCache();
        }
        
        if ($maxAge > 0) {
            $cacheControl->setMaxAge($maxAge);
        }
        
        if (strpos($header, 'must-revalidate') !== false) {
            $cacheControl->setMustRevalidate(true);
        }
        
        if (strpos($header, 'no-store') !== false) {
            $cacheControl->disableCache(true);
        } else {
            $cacheControl->enableCache(true);
        }
    }
}
