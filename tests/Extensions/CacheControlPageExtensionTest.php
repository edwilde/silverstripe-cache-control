<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlPageExtension;
use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlPageExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'CacheControlPageExtensionTest.yml';

    protected static $required_extensions = [
        SiteTree::class => [
            CacheControlPageExtension::class,
        ],
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
    ];

    public function testExtensionAddsFields()
    {
        $page = SiteTree::create();
        $fields = $page->getCMSFields();

        $this->assertNotNull($fields->fieldByName('Root.CacheControl'));
        $this->assertNotNull($fields->fieldByName('Root.CacheControl.OverrideCacheControl'));
    }

    public function testDefaultOverrideDisabled()
    {
        $page = SiteTree::create();
        $this->assertFalse((bool)$page->OverrideCacheControl, 'Override should be disabled by default');
    }

    public function testGetCacheControlHeaderUsesPageOverride()
    {
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'private';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '300';
        $page->EnableMustRevalidate = false;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertEquals('private, max-age=300', $header);
    }

    public function testGetCacheControlHeaderFallsBackToSiteConfig()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = 7200;
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->OverrideCacheControl = false;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertEquals('public, max-age=7200', $header);
    }

    public function testGetCacheControlHeaderReturnsNullWhenOverrideDisabledAndNoSiteSettings()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = false;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->OverrideCacheControl = false;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertNull($header);
    }

    public function testGetCacheControlHeaderWithOverrideNoStore()
    {
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheDuration = 'nostore';
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertStringContainsString('no-store', $header);
    }

    public function testPageOverrideIgnoresSiteConfig()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = 9999;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'private';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = 'custom';
        $page->MaxAge = 60;
        $page->EnableMustRevalidate = false;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertEquals('private, max-age=60', $header);
        $this->assertStringNotContainsString('9999', $header);
    }

    public function testGetEffectiveCacheControlHeaderDescription()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->OverrideCacheControl = false;
        $page->write();

        $description = $page->getEffectiveCacheControlDescription();
        $this->assertStringContainsString('public, max-age=120', $description);
        $this->assertStringContainsString('inherited', strtolower($description));
    }

    public function testGetEffectiveCacheControlHeaderDescriptionWithOverride()
    {
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'private';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '120';
        $page->EnableMustRevalidate = false;
        $page->write();

        $description = $page->getEffectiveCacheControlDescription();
        $this->assertStringContainsString('private', $description);
        $this->assertStringContainsString('page-specific', strtolower($description));
    }

    /**
     * Test that when override is enabled but cache control is disabled,
     * it returns null instead of falling back to site config.
     * This allows editors to explicitly disable caching on specific pages.
     */
    public function testOverrideWithCacheControlDisabledReturnsNull()
    {
        // Set up site config with caching enabled
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->write();

        // Create page with override enabled but cache control disabled
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = false; // Explicitly disabled at page level
        $page->write();

        $header = $page->getCacheControlHeader();

        // Should return null, NOT fall back to site config
        $this->assertNull($header, 'When override is enabled but cache control is disabled, should return null');
    }
}
