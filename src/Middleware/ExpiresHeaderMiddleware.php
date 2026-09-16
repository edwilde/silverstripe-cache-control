<?php

namespace Edwilde\CacheControl\Middleware;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Middleware\HTTPMiddleware;

/**
 * Keep the Expires header consistent with the response's final Cache-Control state.
 *
 * An Expires header alone permits caching under RFC 9111, so it must never be
 * written before the request outcome is known: one set optimistically during
 * controller init survives on an error response and leaves the error cacheable.
 * Running as request middleware, this sees the definitive Cache-Control, so it
 * sets Expires to now + max-age on cacheable success responses and removes it
 * everywhere else.
 *
 * @package Edwilde\CacheControl
 */
class ExpiresHeaderMiddleware implements HTTPMiddleware
{
    /**
     * Set Expires to now + max-age on cacheable success responses; remove it
     * from error responses, uncacheable responses, and responses without a
     * max-age.
     *
     * @param HTTPRequest $request
     * @param callable $delegate
     * @return mixed
     */
    public function process(HTTPRequest $request, callable $delegate)
    {
        $response = $delegate($request);

        if (!$response) {
            return $response;
        }

        $cacheControl = (string) $response->getHeader('Cache-Control');
        $isError = $response->getStatusCode() >= 400;
        $isUncacheable = strpos($cacheControl, 'no-store') !== false
            || strpos($cacheControl, 'no-cache') !== false;

        if ($isError || $isUncacheable || !preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
            $response->removeHeader('Expires');

            return $response;
        }

        $response->addHeader('Expires', gmdate('D, d M Y H:i:s', time() + (int) $matches[1]) . ' GMT');

        return $response;
    }
}
