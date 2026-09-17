<?php

namespace Edwilde\CacheControl\Tests;

use Edwilde\CacheControl\StaleDirectives;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;

class StaleDirectivesTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
    ];

    public function testPresetResolvesToSeconds()
    {
        $this->assertSame(86400, StaleDirectives::resolve('86400', 0));
    }

    public function testOffPresetResolvesToZero()
    {
        $this->assertSame(0, StaleDirectives::resolve(StaleDirectives::PRESET_OFF, 3600));
    }

    public function testCustomPresetReadsCustomValue()
    {
        $this->assertSame(43200, StaleDirectives::resolve(StaleDirectives::PRESET_CUSTOM, 43200));
    }

    public function testCustomValueBelowOneResolvesToZero()
    {
        $this->assertSame(0, StaleDirectives::resolve(StaleDirectives::PRESET_CUSTOM, 0));
        $this->assertSame(0, StaleDirectives::resolve(StaleDirectives::PRESET_CUSTOM, -5));
    }

    public function testForSourceOmitsDirectivesThatAreOff()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->StaleWhileRevalidatePreset = '86400';
        $siteConfig->StaleIfErrorPreset = StaleDirectives::PRESET_OFF;

        $this->assertSame(['stale-while-revalidate' => 86400], StaleDirectives::forSource($siteConfig));
    }

    public function testResolveAllKeepsDirectivesThatAreOff()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->StaleWhileRevalidatePreset = '86400';
        $siteConfig->StaleIfErrorPreset = StaleDirectives::PRESET_OFF;

        $this->assertSame(
            ['stale-while-revalidate' => 86400, 'stale-if-error' => 0],
            StaleDirectives::resolveAll($siteConfig)
        );
    }

    public function testHasGracePeriod()
    {
        $siteConfig = SiteConfig::current_site_config();
        $this->assertFalse(StaleDirectives::hasGracePeriod($siteConfig));

        $siteConfig->StaleIfErrorPreset = '604800';
        $this->assertTrue(StaleDirectives::hasGracePeriod($siteConfig));
    }
}
