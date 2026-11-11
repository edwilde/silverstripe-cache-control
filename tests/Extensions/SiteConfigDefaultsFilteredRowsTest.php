<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControl\SiteConfigDefaults;
use Edwilde\CacheControl\Tests\DefaultsConfig;
use Edwilde\CacheControl\Tests\LowestIDRowOnlyExtension;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class SiteConfigDefaultsFilteredRowsTest extends SapphireTest
{
    protected static $fixture_file = 'SiteConfigDefaultsFilteredRowsTest.yml';

    protected static $required_extensions = [
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
            LowestIDRowOnlyExtension::class,
        ],
    ];

    /**
     * Check an ordinary query returns only the lowest ID while a query by ID returns both rows
     */
    public function testOrdinaryQueryReturnsOneRowAndAQueryByIDReturnsBoth(): void
    {
        $this->assertSame(1, SiteConfig::get()->count());
        $this->assertSame(2, SiteConfig::get()->byIDs($this->fixtureIDs())->count());
    }

    /**
     * Check a build updates both rows although an ordinary query returns only one
     */
    public function testBuildUpdatesEveryRowWhileAnOrdinaryQueryReturnsOne(): void
    {
        DefaultsConfig::set(2, ['EnableCacheControl' => true, 'CacheType' => 'public']);

        SiteConfig::singleton()->requireDefaultRecords();

        foreach (['visible', 'hidden'] as $fixtureName) {
            $row = SiteConfig::get()->byID($this->idFromFixture(SiteConfig::class, $fixtureName));
            $this->assertSame('public', $row->CacheType, $fixtureName);
            $this->assertSame(2, (int)$row->CacheControlDefaultsVersion, $fixtureName);
        }
    }

    /**
     * Check the row source returns both rows in ID order although an ordinary query returns only one
     */
    public function testAllRowsReturnsEveryRowWhileAnOrdinaryQueryReturnsOne(): void
    {
        $ids = array_map(static fn (SiteConfig $row): int => (int)$row->ID, SiteConfigDefaults::allRows()->toArray());

        $this->assertSame($this->fixtureIDs(), $ids);
    }

    /**
     * Get the IDs of the two fixture rows, lowest first
     *
     * @return int[]
     */
    private function fixtureIDs(): array
    {
        return [
            $this->idFromFixture(SiteConfig::class, 'visible'),
            $this->idFromFixture(SiteConfig::class, 'hidden'),
        ];
    }
}
