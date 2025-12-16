<?php

namespace Edwilde\CacheControls\Tests\Middleware;

use Edwilde\CacheControls\Extensions\CacheControlPageExtension;
use Edwilde\CacheControls\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControls\Middleware\CacheControlMiddleware;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlMiddlewareTest extends SapphireTest
{
    protected static $required_extensions = [
        SiteTree::class => [
            CacheControlPageExtension::class,
        ],
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
    ];

    protected $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CacheControlMiddleware();
    }

    public function testMiddlewareDoesNothingWhenNoPage()
    {
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        
        $response = new HTTPResponse();
        $response->setBody('Test');

        $result = $this->middleware->process($request, function ($req) use ($response) {
            return $response;
        });

        $this->assertNull($result->getHeader('Cache-Control'));
    }

    public function testMiddlewareAppliesSiteConfigHeader()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->EnableMaxAge = true;
        $siteConfig->MaxAge = 600;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->Title = 'Test Page';
        $page->write();
        $page->publishRecursive();

        $controller = ContentController::create($page);
        
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        
        $response = new HTTPResponse();
        $response->setBody('Test');

        $result = $this->middleware->process($request, function ($req) use ($response, $controller) {
            $req->match('$URLSegment');
            $controller->setRequest($req);
            $controller->doInit();
            return $response;
        });

        $cacheHeader = $result->getHeader('Cache-Control');
        $this->assertNotNull($cacheHeader);
        $this->assertEquals('public, max-age=600', $cacheHeader);
    }

    public function testMiddlewareAppliesPageOverrideHeader()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->EnableMaxAge = true;
        $siteConfig->MaxAge = 9999;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->Title = 'Test Page With Override';
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'private';
        $page->EnableMaxAge = true;
        $page->MaxAge = 123;
        $page->write();
        $page->publishRecursive();

        $controller = ContentController::create($page);
        
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        
        $response = new HTTPResponse();
        $response->setBody('Test');

        $result = $this->middleware->process($request, function ($req) use ($response, $controller) {
            $req->match('$URLSegment');
            $controller->setRequest($req);
            $controller->doInit();
            return $response;
        });

        $cacheHeader = $result->getHeader('Cache-Control');
        $this->assertNotNull($cacheHeader);
        $this->assertEquals('private, max-age=123', $cacheHeader);
    }

    public function testMiddlewareDoesNotOverrideExistingCacheControlHeader()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->write();

        $page = SiteTree::create();
        $page->Title = 'Test Page';
        $page->write();

        $controller = ContentController::create($page);
        
        $request = new HTTPRequest('GET', '/');
        $request->setSession(new Session([]));
        
        $response = new HTTPResponse();
        $response->setBody('Test');
        $response->addHeader('Cache-Control', 'no-cache, no-store');

        $result = $this->middleware->process($request, function ($req) use ($response, $controller) {
            $req->match('$URLSegment');
            $controller->setRequest($req);
            $controller->doInit();
            return $response;
        });

        $cacheHeader = $result->getHeader('Cache-Control');
        $this->assertEquals('no-cache, no-store', $cacheHeader, 'Should not override existing header');
    }
}
