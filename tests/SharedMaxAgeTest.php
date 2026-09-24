<?php

namespace Edwilde\CacheControl\Tests;

use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControl\SharedMaxAge;
use Edwilde\CacheControl\StaleDirectives;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class SharedMaxAgeTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
    ];

    /**
     * Check a preset resolves to its seconds value.
     */
    public function testPresetResolvesToSeconds()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->SharedMaxAge = 0;

        $this->assertSame(604800, SharedMaxAge::forSource($siteConfig));
    }

    /**
     * Check the custom preset reads the paired custom field.
     */
    public function testCustomPresetReadsCustomValue()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = 43200;

        $this->assertSame(43200, SharedMaxAge::forSource($siteConfig));
    }

    /**
     * Check a custom value below one second resolves to off.
     */
    public function testCustomValueBelowOneResolvesToZero()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = 0;

        $this->assertSame(0, SharedMaxAge::forSource($siteConfig));
    }

    /**
     * Check the off preset resolves to zero regardless of the custom field.
     */
    public function testOffPresetResolvesToZero()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = StaleDirectives::PRESET_OFF;
        $siteConfig->SharedMaxAge = 3600;

        $this->assertSame(0, SharedMaxAge::forSource($siteConfig));
    }

    /**
     * Check validate adds a field error when the custom value is zero.
     */
    public function testValidateAddsErrorForCustomValueBelowOne()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = 0;

        $result = new ValidationResult();
        SharedMaxAge::validate($siteConfig, $result);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            'Custom CDN cache duration must be at least 1 second.',
            (string)$result->getMessages()[0]['message']
        );
    }

    /**
     * Check validate adds a field error when the custom value exceeds the maximum.
     */
    public function testValidateAddsErrorForCustomValueAboveMax()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = StaleDirectives::MAX_SECONDS + 1;

        $result = new ValidationResult();
        SharedMaxAge::validate($siteConfig, $result);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString(
            'Custom CDN cache duration must be no more than 31536000 seconds (one year).',
            (string)$result->getMessages()[0]['message']
        );
    }

    /**
     * Check validate passes for a custom value within range.
     */
    public function testValidatePassesForCustomValueInRange()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = StaleDirectives::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = 3600;

        $result = new ValidationResult();
        SharedMaxAge::validate($siteConfig, $result);

        $this->assertTrue($result->isValid());
    }

    /**
     * Check validate ignores an out-of-range custom value when the preset is not custom.
     */
    public function testValidateIgnoresCustomValueWhenPresetIsNotCustom()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->SharedMaxAge = 0;

        $result = new ValidationResult();
        SharedMaxAge::validate($siteConfig, $result);

        $this->assertTrue($result->isValid());
    }

    /**
     * Check validate ignores an invalid custom value while the cache type is private.
     */
    public function testValidateIgnoresCustomValueForPrivateCacheType()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->CacheType = 'private';
        $siteConfig->SharedMaxAgePreset = SharedMaxAge::PRESET_CUSTOM;
        $siteConfig->SharedMaxAge = 0;

        $result = new ValidationResult();
        SharedMaxAge::validate($siteConfig, $result);

        $this->assertTrue($result->isValid());
    }

    /**
     * Check the info field only displays for a public, max-age cache configuration.
     */
    public function testInfoFieldShowsOnlyForPublicMaxAge()
    {
        $notice = SharedMaxAge::infoField('SharedMaxAgeNotice');
        $dispatchers = explode(',', $notice->DisplayLogicDispatchers());

        $this->assertEqualsCanonicalizing(['CacheType', 'CacheDuration'], $dispatchers);
        $this->assertStringContainsString('public', $notice->DisplayLogic());
    }
}
