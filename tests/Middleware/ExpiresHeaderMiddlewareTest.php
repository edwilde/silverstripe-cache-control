<?php

namespace Edwilde\CacheControl\Tests\Middleware;

use Edwilde\CacheControl\Middleware\ExpiresHeaderMiddleware;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Dev\SapphireTest;

class ExpiresHeaderMiddlewareTest extends SapphireTest
{
    public function testExpiresMirrorsMaxAgeOnCacheableResponse()
    {
        $response = new HTTPResponse('body', 200);
        $response->addHeader('Cache-Control', 'public, must-revalidate, max-age=300');

        $processed = $this->processResponse($response);
        $expires = $processed->getHeader('Expires');

        $this->assertNotNull($expires, 'A cacheable response should carry an Expires header');
        $this->assertEqualsWithDelta(
            time() + 300,
            strtotime($expires),
            5,
            'Expires should match the Cache-Control max-age'
        );
    }

    public function testExpiresRemovedFromErrorResponse()
    {
        $response = new HTTPResponse('body', 500);
        $response->addHeader('Cache-Control', 'public, max-age=300');
        $response->addHeader('Expires', gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');

        $processed = $this->processResponse($response);

        $this->assertNull($processed->getHeader('Expires'), 'An error response should not carry an Expires header');
    }

    public function testExpiresRemovedFromUncacheableResponse()
    {
        $response = new HTTPResponse('body', 200);
        $response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->addHeader('Expires', gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');

        $processed = $this->processResponse($response);

        $this->assertNull($processed->getHeader('Expires'), 'A no-store response should not carry an Expires header');
    }

    public function testExpiresRemovedWithoutMaxAge()
    {
        $response = new HTTPResponse('body', 200);
        $response->addHeader('Expires', gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');

        $processed = $this->processResponse($response);

        $this->assertNull($processed->getHeader('Expires'), 'A response without max-age should not carry an Expires header');
    }

    /**
     * Run the middleware over a canned response and return the processed response.
     *
     * @param HTTPResponse $response
     * @return HTTPResponse
     */
    protected function processResponse(HTTPResponse $response)
    {
        $middleware = new ExpiresHeaderMiddleware();
        $request = new HTTPRequest('GET', '/');

        return $middleware->process($request, function () use ($response) {
            return $response;
        });
    }
}
