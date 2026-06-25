<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlSiteConfigExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

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
        // Use dataFieldByName to find fields regardless of wrapper/composite field nesting
        $this->assertNotNull($fields->dataFieldByName('CacheType'));
        $this->assertNotNull($fields->dataFieldByName('CacheDuration'));
        $this->assertNotNull($fields->dataFieldByName('MaxAgePreset'));
        $this->assertNotNull($fields->dataFieldByName('MaxAge'));
        $this->assertNotNull($fields->dataFieldByName('EnableMustRevalidate'));
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

    public function testValidationRejectsNegativeCustomMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = -1;

        $result = $siteConfig->validate();
        $this->assertFalse($result->isValid(), 'Validation should fail for negative max age');
    }

    public function testValidationRejectsZeroCustomMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = 0;

        $result = $siteConfig->validate();
        $this->assertFalse($result->isValid(), 'Validation should fail for zero max age');
    }

    public function testValidationPassesForPositiveCustomMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = 60;

        $result = $siteConfig->validate();
        $this->assertTrue($result->isValid(), 'Validation should pass for positive max age');
    }

    public function testNegativeCustomMaxAgeFallsBackToDefault()
    {
        // Test the defensive fallback for invalid data that may already exist in the database.
        // We bypass write() since validation now prevents negative values from being saved.
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = 'custom';
        $siteConfig->MaxAge = -1;
        $siteConfig->EnableMustRevalidate = false;

        $header = $siteConfig->getCacheControlHeader();
        $this->assertEquals('public, max-age=120', $header, 'Negative custom max age should fall back to 120');
    }

    /**
     * The EnableDraftCacheReduction checkbox should be present in SiteConfig CMS fields.
     */
    public function testDraftCacheReductionFieldExists()
    {
        $siteConfig = SiteConfig::current_site_config();
        $fields = $siteConfig->getCMSFields();
        $field = $fields->dataFieldByName('EnableDraftCacheReduction');
        $this->assertNotNull($field, 'EnableDraftCacheReduction field should exist');
    }

    /**
     * Draft cache reduction should be enabled by default in SiteConfig.
     */
    public function testDraftCacheReductionDefaultEnabled()
    {
        $siteConfig = SiteConfig::current_site_config();
        $this->assertTrue((bool)$siteConfig->EnableDraftCacheReduction, 'Draft cache reduction should be enabled by default');
    }
}
