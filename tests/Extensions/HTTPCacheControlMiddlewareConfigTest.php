<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Dev\SapphireTest;

/**
 * The module registers the RFC 5861 directives with the framework middleware via YAML.
 * Without that registration setStateDirective() throws for both names.
 */
class HTTPCacheControlMiddlewareConfigTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testAllowedDirectivesIncludeStaleDirectives()
    {
        $allowed = HTTPCacheControlMiddleware::config()->get('allowed_directives');

        $this->assertContains('stale-while-revalidate', $allowed);
        $this->assertContains('stale-if-error', $allowed);
    }

    public function testAllowedDirectivesKeepFrameworkBuiltIns()
    {
        $allowed = HTTPCacheControlMiddleware::config()->get('allowed_directives');

        $this->assertContains('max-age', $allowed);
        $this->assertContains('must-revalidate', $allowed);
        $this->assertContains('no-store', $allowed);
    }

    public function testStaleDirectivesCanBeSetOnState()
    {
        HTTPCacheControlMiddleware::reset();
        $middleware = HTTPCacheControlMiddleware::singleton();

        $middleware->setStateDirective(
            [HTTPCacheControlMiddleware::STATE_PUBLIC],
            'stale-while-revalidate',
            86400
        );
        $middleware->setStateDirective(
            [HTTPCacheControlMiddleware::STATE_PUBLIC],
            'stale-if-error',
            604800
        );

        $this->assertEquals(
            86400,
            $middleware->getStateDirective(HTTPCacheControlMiddleware::STATE_PUBLIC, 'stale-while-revalidate')
        );
        $this->assertEquals(
            604800,
            $middleware->getStateDirective(HTTPCacheControlMiddleware::STATE_PUBLIC, 'stale-if-error')
        );
    }
}
