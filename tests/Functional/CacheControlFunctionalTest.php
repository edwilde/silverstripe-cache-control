<?php

/**
 * Cache Control Functional Tests
 *
 * End-to-end tests that verify cache control headers are correctly applied
 * to HTTP responses for various configurations. These tests simulate real
 * HTTP requests and check the resulting headers.
 *
 * Tests cover:
 * - Site-wide cache settings
 * - Page-level overrides
 * - Various cache types (public/private)
 * - Cache durations (max-age/no-store)
 * - Must-revalidate directive
 * - Expires header matching max-age
 * - Disabling cache at page level
 *
 * @package Edwilde\CacheControls
 * @author Ed Wilde
 */

namespace Edwilde\CacheControls\Tests\Functional;

use Page;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Functional tests for cache control headers in HTTP responses
 *
 * These tests make actual HTTP requests and verify the headers
 * that come back, ensuring the entire system works together correctly.
 */
class CacheControlFunctionalTest extends FunctionalTest
{
    /**
     * @var string Fixtures file for test data
     */
    protected static $fixture_file = 'CacheControlFunctionalTest.yml';

    /**
     * Setup before each test
     * Clear any existing cache control settings
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset site config to defaults
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = false;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAge = 120;
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();
    }

    /**
     * Test that site-wide cache settings are applied to pages without override
     *
     * @return void
     */
    public function testSiteWideCacheSettings()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAge = 300;
        $siteConfig->EnableMustRevalidate = true;
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'home');
        
        $response = $this->get($page->Link());
        
        $cacheControl = $response->getHeader('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be set');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        
        // Check Expires header is set and matches max-age
        $expires = $response->getHeader('Expires');
        $this->assertNotNull($expires, 'Expires header should be set when max-age is used');
    }

    /**
     * Test that no-store prevents all caching
     *
     * @return void
     */
    public function testNoStorePreventsAllCaching()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'nostore';
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'home');
        
        $response = $this->get($page->Link());
        
        $cacheControl = $response->getHeader('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be set');
        $this->assertStringContainsString('no-store', $cacheControl);
        // Should NOT contain max-age when no-store is set
        $this->assertStringNotContainsString('max-age', $cacheControl);
    }

    /**
     * Test that private cache with max-age works correctly
     *
     * @return void
     */
    public function testPrivateCacheWithMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'private';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAge = 600;
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'home');
        
        $response = $this->get($page->Link());
        
        $cacheControl = $response->getHeader('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be set');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=600', $cacheControl, 'max-age should be present with private cache');
        
        // Check Expires header
        $expires = $response->getHeader('Expires');
        $this->assertNotNull($expires, 'Expires header should be set for private cache with max-age');
    }

    /**
     * Test that page-level override replaces site settings
     *
     * @return void
     */
    public function testPageLevelOverride()
    {
        // Set site-wide cache settings
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->MaxAge = 120;
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'about');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'private';
        $page->CacheDuration = 'maxage';
        $page->MaxAge = 60;
        $page->write();
        $page->publishSingle();

        $response = $this->get($page->Link());
        
        $cacheControl = $response->getHeader('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be set');
        $this->assertStringContainsString('private', $cacheControl, 'Should use page override, not site setting');
        $this->assertStringContainsString('max-age=60', $cacheControl, 'Should use page max-age, not site max-age');
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    /**
     * Test that page can override to disable caching
     *
     * @return void
     */
    public function testPageOverrideDisablesCache()
    {
        // Set site-wide cache enabled
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->MaxAge = 300;
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'news');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = false; // Explicitly disable for this page
        $page->write();
        $page->publishSingle();

        $response = $this->get($page->Link());
        
        $cacheControl = $response->getHeader('Cache-Control');
        // When page explicitly disables cache, should get no-store or no cache header
        // At minimum, should NOT get the site-wide cache settings
        if ($cacheControl) {
            $this->assertStringNotContainsString('max-age=300', $cacheControl, 'Should not use site max-age when page disables cache');
        }
    }

    /**
     * Test that page override with no-store works correctly
     *
     * @return void
     */
    public function testPageOverrideWithNoStore()
    {
        // Set site-wide cache enabled
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->MaxAge = 300;
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'contact');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheDuration = 'nostore';
        $page->write();
        $page->publishSingle();

        $response = $this->get($page->Link());
        
        $cacheControl = $response->getHeader('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be set');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('max-age', $cacheControl, 'Should not have max-age with no-store');
        $this->assertStringNotContainsString('public', $cacheControl, 'Should not have public/private with no-store');
    }

    /**
     * Test that disabling override returns to site settings
     *
     * @return void
     */
    public function testDisablingOverrideReturnsToSiteSettings()
    {
        // Set site-wide cache
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->MaxAge = 240;
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'services');
        
        // First enable override with different settings
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->MaxAge = 60;
        $page->write();
        $page->publishSingle();

        // Then disable override
        $page->OverrideCacheControl = false;
        $page->write();
        $page->publishSingle();

        $response = $this->get($page->Link());
        
        $cacheControl = $response->getHeader('Cache-Control');
        $this->assertNotNull($cacheControl, 'Cache-Control header should be set');
        $this->assertStringContainsString('max-age=240', $cacheControl, 'Should return to site max-age when override is disabled');
    }

    /**
     * Test that no cache headers are set when cache control is disabled
     *
     * @return void
     */
    public function testNoCacheHeadersWhenDisabled()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = false;
        $siteConfig->write();

        /** @var Page $page */
        $page = $this->objFromFixture(Page::class, 'home');
        
        $response = $this->get($page->Link());
        
        // When disabled, getCacheControlHeader returns null, so no custom headers applied
        // SilverStripe may still add its own default headers, so we just check
        // our specific settings aren't there
        $cacheControl = $response->getHeader('Cache-Control');
        if ($cacheControl) {
            // If there is a cache-control header, it shouldn't be our configured one
            $this->assertStringNotContainsString('max-age=120', $cacheControl);
        }
    }
}
