<?php

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\Control\HTTP;
use SilverStripe\Core\Extension;

class CacheControlContentControllerExtension extends Extension
{
    public function onAfterInit()
    {
        $page = $this->owner->data();
        
        file_put_contents(BASE_PATH . '/controller-debug.log', sprintf(
            "[%s] Controller init - Page: %s\n",
            date('Y-m-d H:i:s'),
            $page ? get_class($page) : 'NULL'
        ), FILE_APPEND);
        
        if ($page && $page->hasMethod('getCacheControlHeader')) {
            $cacheHeader = $page->getCacheControlHeader();
            
            file_put_contents(BASE_PATH . '/controller-debug.log', sprintf(
                "[%s] Cache header: %s\n",
                date('Y-m-d H:i:s'),
                $cacheHeader ?: 'NULL'
            ), FILE_APPEND);
            
            if ($cacheHeader) {
                // Store on request so middleware can use it later
                $this->owner->getRequest()->addHeader('X-Cache-Control-Override', $cacheHeader);
            }
        }
    }
}
