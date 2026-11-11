<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlPageExtension;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class CacheControlPageSettingsPartialExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'CacheControlPageSettingsPartialExtensionTest.yml';

    protected static $illegal_extensions = [
        SiteTree::class => [
            CacheControlPageExtension::class,
        ],
    ];

    protected static $required_extensions = [
        RedirectorPage::class => [
            CacheControlPageExtension::class,
        ],
    ];

    /**
     * Set every config the test depends on
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(RedirectorPage::class, 'enable_cache_inheritance', true);
        Config::modify()->set(RedirectorPage::class, 'enable_cache_control_page_settings', true);
    }

    /**
     * Check an ancestor whose class lacks the page extension is skipped instead of failing
     */
    public function testAncestorWithoutThePageExtensionIsSkipped(): void
    {
        $child = $this->objFromFixture(RedirectorPage::class, 'extended_child');

        $this->assertFalse($child->Parent()->hasExtension(CacheControlPageExtension::class));
        $this->assertTrue($child->hasExtension(CacheControlPageExtension::class));
        $this->assertTrue((bool)$child->config()->get('enable_cache_inheritance'));
        $this->assertNull($child->findInheritedCacheSource());
    }
}
