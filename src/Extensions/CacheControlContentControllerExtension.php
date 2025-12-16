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
                // Store on request so middleware can use it later
                $this->owner->getRequest()->addHeader('X-Cache-Control-Override', $cacheHeader);
            }
        }
    }
}
