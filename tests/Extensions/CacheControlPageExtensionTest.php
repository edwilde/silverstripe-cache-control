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

    public function testValidationRejectsNegativeCustomMaxAge()
    {
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = 'custom';
        $page->MaxAge = -1;

        $result = $page->validate();
        $this->assertFalse($result->isValid(), 'Validation should fail for negative max age');
    }

    public function testValidationSkippedWhenOverrideDisabled()
    {
        $page = SiteTree::create();
        $page->OverrideCacheControl = false;
        $page->MaxAgePreset = 'custom';
        $page->MaxAge = -1;

        $result = $page->validate();
        $this->assertTrue($result->isValid(), 'Validation should not run when override is disabled');
    }

    public function testNegativeCustomMaxAgeFallsBackToDefault()
    {
        // Test the defensive fallback for invalid data that may already exist in the database.
        // We bypass write() since validation now prevents negative values from being saved.
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = 'custom';
        $page->MaxAge = -1;
        $page->EnableMustRevalidate = false;

        $header = $page->getCacheControlHeader();
        $this->assertEquals('public, max-age=120', $header, 'Negative custom max age should fall back to 120');
    }

    /**
     * When override is not yet enabled, form fields should display site config values
     * rather than the page's stored defaults, so what you see is what you get on save.
     */
    public function testCMSFieldsPrePopulatedFromSiteConfigWhenOverrideDisabled()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'private';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->MaxAge = 300;
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = SiteTree::create();
        $page->OverrideCacheControl = false;
        $page->write();

        $fields = $page->getCMSFields();

        $this->assertEquals('private', $fields->dataFieldByName('CacheType')->getValue());
        $this->assertEquals('300', $fields->dataFieldByName('MaxAgePreset')->getValue());
        $this->assertEquals(300, $fields->dataFieldByName('MaxAge')->getValue());
        $this->assertEquals(false, (bool)$fields->dataFieldByName('EnableMustRevalidate')->getValue());
    }

    /**
     * When override is already enabled, form fields should show the page's own saved values,
     * not the site config values.
     */
    public function testCMSFieldsShowPageValuesWhenOverrideEnabled()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->write();

        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'private';
        $page->MaxAgePreset = '600';
        $page->write();

        $fields = $page->getCMSFields();

        $this->assertEquals('600', $fields->dataFieldByName('MaxAgePreset')->getValue());
        $this->assertEquals('private', $fields->dataFieldByName('CacheType')->getValue());
    }

    /**
     * Enabling override and saving without changing any values should store whatever
     * was displayed in the form (site config values), not silently swap to something else.
     */
    public function testEnablingOverrideAndSavingUnchangedStoresSiteConfigValues()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->MaxAge = 300;
        $siteConfig->EnableMustRevalidate = true;
        $siteConfig->write();

        // Simulate the user enabling override and saving without changing the pre-populated values.
        // The form would have shown '300' (from site config), so that's what gets submitted.
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->MaxAgePreset = '300';
        $page->MaxAge = 300;
        $page->EnableMustRevalidate = true;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertEquals('public, max-age=300, must-revalidate', $header);
    }

    /**
     * Enabling override and explicitly choosing a value that matches the page's DB default
     * (e.g., 120s) should not be overwritten — the user's choice must be respected.
     */
    public function testEnablingOverrideWithExplicitValueMatchingDbDefaultIsPreserved()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->write();

        // The user sees '300' pre-populated but explicitly changes to '120'.
        $page = SiteTree::create();
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '120';
        $page->EnableMustRevalidate = false;
        $page->write();

        $header = $page->getCacheControlHeader();
        $this->assertEquals('public, max-age=120', $header, 'Explicit choice of 120 should be preserved');
    }
}
