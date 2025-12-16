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
        // Parse the header (e.g. "public, max-age=999")
        $directives = array_map('trim', explode(',', $header));
        
        foreach ($directives as $directive) {
            if (strpos($directive, 'max-age=') === 0) {
                $maxAge = (int)substr($directive, 8);
                HTTP::set_cache_age($maxAge);
            } elseif ($directive === 'public') {
                HTTP::set_cache_age(0); // Will be set by max-age
            } elseif ($directive === 'private') {
                HTTP::set_cache_age(0);
                HTTP::privateCache();
            } elseif ($directive === 'no-store') {
                HTTP::set_cache_age(0);
                $this->owner->getResponse()->addHeader('Cache-Control', 'no-store');
            } elseif ($directive === 'must-revalidate') {
                HTTP::set_cache_age(0);
                $this->owner->getResponse()->addHeader('Cache-Control', 'must-revalidate');
            }
        }
        
        // Now apply the headers
        HTTP::add_cache_headers($this->owner->getResponse());
        
        // Finally, override with our exact header
        $this->owner->getResponse()->addHeader('Cache-Control', $header);
    }
}
