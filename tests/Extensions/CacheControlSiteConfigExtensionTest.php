<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControl\SharedMaxAge;
use Edwilde\CacheControl\StaleDirectives;
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
        $this->assertNotNull($fields->dataFieldByName('StaleWhileRevalidatePreset'));
        $this->assertNotNull($fields->dataFieldByName('StaleWhileRevalidate'));
        $this->assertNotNull($fields->dataFieldByName('StaleIfErrorPreset'));
        $this->assertNotNull($fields->dataFieldByName('StaleIfError'));
    }

    public function testDefaultValues()
    {
        $siteConfig = SiteConfig::current_site_config();

        $this->assertFalse((bool)$siteConfig->EnableCacheControl, 'Cache control should be disabled by default');
        $this->assertEquals(120, $siteConfig->MaxAge, 'Default max-age should be 120 seconds');
        $this->assertEquals('0', $siteConfig->StaleWhileRevalidatePreset, 'Refresh grace period should default to off');
        $this->assertEquals(0, $siteConfig->StaleWhileRevalidate);
        $this->assertEquals('0', $siteConfig->StaleIfErrorPreset, 'Error grace period should default to off');
        $this->assertEquals(0, $siteConfig->StaleIfError);
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

    public function testGetCacheControlHeaderWithStaleWhileRevalidate()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = true;
        $siteConfig->StaleWhileRevalidatePreset = '86400';
        $siteConfig->write();

        $this->assertEquals(
            'public, max-age=120, stale-while-revalidate=86400',
            $siteConfig->getCacheControlHeader(),
            'A grace period should replace must-revalidate, which forbids serving stale content'
        );
    }

    public function testGetCacheControlHeaderWithBothStaleDirectives()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->StaleWhileRevalidatePreset = '21600';
        $siteConfig->StaleIfErrorPreset = '604800';
        $siteConfig->write();

        $this->assertEquals(
            'public, max-age=120, stale-while-revalidate=21600, stale-if-error=604800',
            $siteConfig->getCacheControlHeader()
        );
    }

    public function testStaleCustomValueUsed()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->StaleWhileRevalidatePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleWhileRevalidate = 43200;
        $siteConfig->write();

        $this->assertEquals(
            'public, max-age=120, stale-while-revalidate=43200',
            $siteConfig->getCacheControlHeader()
        );
    }

    public function testStaleCustomBelowOneIsOff()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = true;
        $siteConfig->StaleWhileRevalidatePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleWhileRevalidate = 0;

        $this->assertEquals(
            'public, max-age=120, must-revalidate',
            $siteConfig->getCacheControlHeader(),
            'An unusable custom value turns the grace period off, restoring must-revalidate'
        );
    }

    public function testStaleDirectivesOmittedWithNoStore()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'nostore';
        $siteConfig->StaleWhileRevalidatePreset = '86400';
        $siteConfig->write();

        $this->assertEquals('no-store', $siteConfig->getCacheControlHeader());
    }

    public function testValidationRejectsZeroCustomStaleWhileRevalidate()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->StaleWhileRevalidatePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleWhileRevalidate = 0;

        $this->assertFalse($siteConfig->validate()->isValid());
    }

    public function testValidationRejectsZeroCustomStaleIfError()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->StaleIfErrorPreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleIfError = 0;

        $this->assertFalse($siteConfig->validate()->isValid());
    }

    public function testValidationRejectsCustomStaleWhileRevalidateAboveOneYear()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->StaleWhileRevalidatePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleWhileRevalidate = StaleDirectives::MAX_SECONDS + 1;

        $this->assertFalse($siteConfig->validate()->isValid());
    }

    public function testValidationRejectsCustomStaleIfErrorAboveOneYear()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->StaleIfErrorPreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleIfError = StaleDirectives::MAX_SECONDS + 1;

        $this->assertFalse($siteConfig->validate()->isValid());
    }

    public function testValidationPassesForCustomStaleValuesAtOneYear()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->StaleWhileRevalidatePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleWhileRevalidate = StaleDirectives::MAX_SECONDS;
        $siteConfig->StaleIfErrorPreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleIfError = StaleDirectives::MAX_SECONDS;

        $this->assertTrue($siteConfig->validate()->isValid());
    }

    public function testValidationPassesForPositiveCustomStaleValues()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->StaleWhileRevalidatePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleWhileRevalidate = 3600;
        $siteConfig->StaleIfErrorPreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->StaleIfError = 60;

        $this->assertTrue($siteConfig->validate()->isValid());
    }

    /**
     * Check the CDN cache duration fields exist inside the advanced toggle, after MaxAge.
     */
    public function testSharedMaxAgeFieldsExist()
    {
        $siteConfig = SiteConfig::current_site_config();
        $fields = $siteConfig->getCMSFields();

        $this->assertNotNull($fields->dataFieldByName('SharedMaxAgePreset'));
        $this->assertNotNull($fields->dataFieldByName('SharedMaxAge'));

        $names = array_keys($fields->dataFields());
        $maxAgeIndex = array_search('MaxAge', $names, true);
        $presetIndex = array_search('SharedMaxAgePreset', $names, true);
        $customIndex = array_search('SharedMaxAge', $names, true);

        $this->assertNotFalse($maxAgeIndex);
        $this->assertGreaterThan($maxAgeIndex, $presetIndex, 'CDN preset should sit after MaxAge');
        $this->assertGreaterThan($presetIndex, $customIndex, 'CDN custom field should sit after the preset');
    }

    /**
     * Check the CDN cache duration preset defaults to off.
     */
    public function testSharedMaxAgeDefaultsOff()
    {
        $siteConfig = SiteConfig::current_site_config();
        $this->assertEquals(SharedMaxAge::PRESET_OFF, $siteConfig->SharedMaxAgePreset);
        $this->assertEquals(0, $siteConfig->SharedMaxAge);
    }

    /**
     * Check the preview inserts s-maxage after max-age when public and a CDN duration is set.
     */
    public function testGetCacheControlHeaderWithSharedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $this->assertEquals(
            'public, max-age=300, s-maxage=604800, must-revalidate',
            $siteConfig->getCacheControlHeader()
        );
    }

    /**
     * Check the preview omits s-maxage when the CDN duration is off.
     */
    public function testGetCacheControlHeaderOmitsSharedMaxAgeWhenOff()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = SharedMaxAge::PRESET_OFF;
        $siteConfig->write();

        $this->assertStringNotContainsString('s-maxage', $siteConfig->getCacheControlHeader());
    }

    /**
     * Check the preview omits s-maxage for a private cache type.
     */
    public function testGetCacheControlHeaderOmitsSharedMaxAgeWhenPrivate()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'private';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $this->assertStringNotContainsString('s-maxage', $siteConfig->getCacheControlHeader());
    }

    /**
     * Check the preview omits s-maxage for no-store.
     */
    public function testGetCacheControlHeaderOmitsSharedMaxAgeWithNoStore()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'nostore';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $this->assertEquals('no-store', $siteConfig->getCacheControlHeader());
    }

    /**
     * Check the preview combines s-maxage with a refresh grace period.
     */
    public function testGetCacheControlHeaderWithSharedMaxAgeAndGracePeriod()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->StaleWhileRevalidatePreset = '3600';
        $siteConfig->write();

        $this->assertEquals(
            'public, max-age=300, s-maxage=604800, stale-while-revalidate=3600',
            $siteConfig->getCacheControlHeader()
        );
    }

    /**
     * Check the preview uses the custom CDN cache duration value.
     */
    public function testGetCacheControlHeaderWithCustomSharedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = SharedMaxAge::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = 43200;
        $siteConfig->write();

        $this->assertEquals(
            'public, max-age=300, s-maxage=43200, must-revalidate',
            $siteConfig->getCacheControlHeader()
        );
    }

    /**
     * Check validation rejects a custom CDN cache duration below one second.
     */
    public function testValidationRejectsZeroCustomSharedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->SharedMaxAgePreset = SharedMaxAge::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = 0;

        $this->assertFalse($siteConfig->validate()->isValid());
    }
}
