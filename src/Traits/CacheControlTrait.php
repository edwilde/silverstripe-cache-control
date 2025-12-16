<?php

namespace Edwilde\CacheControls\Traits;

trait CacheControlTrait
{
    protected function buildCacheControlHeader()
    {
        if (!$this->EnableCacheControl) {
            return null;
        }

        $directives = [];

        if ($this->EnableNoStore) {
            $directives[] = 'no-store';
        }

        if ($this->CacheType) {
            $directives[] = $this->CacheType;
        }

        if ($this->EnableMaxAge && !$this->EnableNoStore) {
            $maxAge = (int)$this->MaxAge ?: 120;
            $directives[] = 'max-age=' . $maxAge;
        }

        if ($this->EnableMustRevalidate) {
            $directives[] = 'must-revalidate';
        }

        return !empty($directives) ? implode(', ', $directives) : null;
    }
}
