<?php

namespace Edwilde\CacheControls\Tests\Extensions;

use Edwilde\CacheControls\Extensions\CacheControlPageExtension;
use Edwilde\CacheControls\Extensions\CacheControlSiteConfigExtension;
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
        $page->EnableMaxAge = true;
        $page->MaxAge = 300;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertEquals('private, max-age=300', $header);
    }

    public function testGetCacheControlHeaderFallsBackToSiteConfig()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->EnableMaxAge = true;
        $siteConfig->MaxAge = 7200;
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
        $page->EnableNoStore = true;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertStringContainsString('no-store', $header);
    }

    public function testPageOverrideIgnoresSiteConfig()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->EnableMaxAge = true;
        $siteConfig->MaxAge = 9999;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'private';
        $page->EnableMaxAge = true;
        $page->MaxAge = 60;
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
        $siteConfig->EnableMaxAge = true;
        $siteConfig->MaxAge = 120;
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
        $page->write();

        $description = $page->getEffectiveCacheControlDescription();
        $this->assertStringContainsString('private', $description);
        $this->assertStringContainsString('page-specific', strtolower($description));
    }
}
