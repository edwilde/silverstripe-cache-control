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
                HTTP::set_cache_age(0);
                HTTP::add_cache_headers($this->owner->getResponse());
                $this->owner->getResponse()->removeHeader('Cache-Control');
                $this->owner->getResponse()->addHeader('Cache-Control', $cacheHeader);
            }
        }
    }
}
