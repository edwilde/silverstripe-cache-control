<?php

namespace Edwilde\CacheControls\Tests\Extensions;

use Edwilde\CacheControls\Extensions\CacheControlSiteConfigExtension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlSiteConfigExtensionTest extends SapphireTest
{
    protected static $required_extensions = [
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
    ];

    public function testExtensionAddsFields()
    {
        $siteConfig = SiteConfig::current_site_config();
        $fields = $siteConfig->getCMSFields();

        $this->assertNotNull($fields->fieldByName('Root.CacheControl.EnableCacheControl'));
        $this->assertNotNull($fields->dataFieldByName('CacheType'));
        $this->assertNotNull($fields->dataFieldByName('CacheDuration'));
        $this->assertNotNull($fields->fieldByName('Root.CacheControl.MaxAgePreset'));
        $this->assertNotNull($fields->fieldByName('Root.CacheControl.MaxAge'));
        $this->assertNotNull($fields->fieldByName('Root.CacheControl.EnableMustRevalidate'));
    }

    public function testDefaultValues()
    {
        $siteConfig = SiteConfig::current_site_config();
        
        $this->assertFalse((bool)$siteConfig->EnableCacheControl, 'Cache control should be disabled by default');
        $this->assertEquals(120, $siteConfig->MaxAge, 'Default max-age should be 120 seconds');
    }

    public function testGetCacheControlHeaderWhenDisabled()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = false;
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertNull($header, 'Should return null when cache control is disabled');
    }

    public function testGetCacheControlHeaderWithPublicAndMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertEquals('public, max-age=3600, must-revalidate', $header);
    }

    public function testGetCacheControlHeaderWithPrivate()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'private';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertEquals('private, max-age=120', $header);
    }

    public function testGetCacheControlHeaderWithMustRevalidate()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = true;
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertStringContainsString('must-revalidate', $header);
    }

    public function testGetCacheControlHeaderWithNoStore()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'nostore';
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertEquals('no-store', $header);
        $this->assertStringNotContainsString('max-age', $header, 'no-store should ignore max-age');
    }

    public function testGetCacheControlHeaderComplexExample()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = 7200;
        $siteConfig->EnableMustRevalidate = true;
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertEquals('public, max-age=7200, must-revalidate', $header);
    }

    public function testMaxAgeUsesPresetValue()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertEquals('public, max-age=3600', $header);
    }

    public function testMaxAgeUsesCustomValue()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = 999;
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $header = $siteConfig->getCacheControlHeader();
        $this->assertEquals('public, max-age=999', $header);
    }
}
