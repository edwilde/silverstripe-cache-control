<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlContentControllerExtension;
use Edwilde\CacheControl\Extensions\CacheControlPageExtension;
use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use ReflectionProperty;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlPageSettingsToggleTest extends SapphireTest
{
    protected static $fixture_file = 'CacheControlPageSettingsToggleTest.yml';

    protected static $required_extensions = [
        SiteTree::class => [
            CacheControlPageExtension::class,
        ],
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
        ContentController::class => [
            CacheControlContentControllerExtension::class,
        ],
    ];

    /**
     * Every field name the module adds to a page
     */
    private const MODULE_FIELDS = [
        'OverrideCacheControl',
        'EnableCacheControl',
        'CacheType',
        'CacheDuration',
        'MaxAge',
        'MaxAgePreset',
        'SharedMaxAgePreset',
        'SharedMaxAge',
        'EnableMustRevalidate',
        'StaleWhileRevalidatePreset',
        'StaleWhileRevalidate',
        'StaleIfErrorPreset',
        'StaleIfError',
        'ApplyCacheToChildren',
        'HasPendingDraftChanges',
        'CacheControlHeader',
        'CurrentCacheControl',
        'PageCacheControlSettings',
    ];

    /**
     * Give the middleware production-like defaults and set every config the tests depend on
     */
    protected function setUp(): void
    {
        parent::setUp();

        HTTPCacheControlMiddleware::config()
            ->set('defaultState', HTTPCacheControlMiddleware::STATE_ENABLED)
            ->set('defaultForcingLevel', 0);
        HTTPCacheControlMiddleware::reset();

        Config::modify()->set(SiteTree::class, 'enable_cache_inheritance', true);
        Config::modify()->set(SiteTree::class, 'enable_cache_control_page_settings', true);
        Config::modify()->set(SiteTree::class, 'draft_cache_max_age', 10);
    }

    /**
     * Turn the page settings off for every page type
     */
    private function turnOffForAllPages(): void
    {
        Config::modify()->set(SiteTree::class, 'enable_cache_control_page_settings', false);
    }

    /**
     * Run the controller init for a page and return the middleware it configured
     */
    private function initialise(SiteTree $page): HTTPCacheControlMiddleware
    {
        ContentController::create($page)->doInit();

        return HTTPCacheControlMiddleware::singleton();
    }

    /**
     * Check the fixture site config is the one the module reads, so site-wide assertions mean something
     */
    public function testFixtureSiteConfigIsCurrent(): void
    {
        $this->assertSame(
            $this->idFromFixture(SiteConfig::class, 'site'),
            (int)SiteConfig::current_site_config()->ID
        );
    }

    /**
     * Check the page tab and fields exist while the switch is on
     */
    public function testFieldsPresentWhileSwitchIsOn(): void
    {
        $fields = $this->objFromFixture(SiteTree::class, 'override_page')->getCMSFields();

        $this->assertNotNull($fields->fieldByName('Root.CacheControl'));
        $this->assertNotNull($fields->fieldByName('Root.CacheControl.CacheControlHeader'));
    }

    /**
     * Check no module field, heading or tab is built while the switch is off
     */
    public function testNoFieldsBuiltWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();

        $fields = $this->objFromFixture(SiteTree::class, 'override_page')->getCMSFields();

        $this->assertNull($fields->fieldByName('Root.CacheControl')?->getName());
        $this->assertNull($fields->fieldByName('CacheControlHeader')?->getName());
        foreach (self::MODULE_FIELDS as $name) {
            $this->assertNull($fields->dataFieldByName($name)?->getName(), $name . ' should not be in the form');
            $this->assertNull($fields->fieldByName($name)?->getName(), $name . ' should not be in the form');
        }
    }

    /**
     * Check a page with a saved override sends the site-wide header while the switch is off
     */
    public function testOverridePageFollowsSiteSettingsWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();

        $middleware = $this->initialise($this->objFromFixture(SiteTree::class, 'override_page'));

        $this->assertSame(HTTPCacheControlMiddleware::STATE_PUBLIC, $middleware->getState());
        $this->assertSame(300, (int)$middleware->getDirective('max-age'));
    }

    /**
     * Check a child of a page that applies to children follows the site-wide header while the switch is off
     */
    public function testInheritingChildFollowsSiteSettingsWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();

        $middleware = $this->initialise($this->objFromFixture(SiteTree::class, 'child_page'));

        $this->assertSame(HTTPCacheControlMiddleware::STATE_PUBLIC, $middleware->getState());
        $this->assertSame(300, (int)$middleware->getDirective('max-age'));
    }

    /**
     * Check the control case: with the switch on the same child inherits the parent's private header
     */
    public function testChildInheritsFromParentWhileSwitchIsOn(): void
    {
        $middleware = $this->initialise($this->objFromFixture(SiteTree::class, 'child_page'));

        $this->assertSame(HTTPCacheControlMiddleware::STATE_PRIVATE, $middleware->getState());
        $this->assertSame(600, (int)$middleware->getDirective('max-age'));
    }

    /**
     * Check the header the CMS shows is the site-wide header while the switch is off
     */
    public function testHeaderIsTheSiteHeaderWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();

        $page = $this->objFromFixture(SiteTree::class, 'override_page');

        $this->assertSame('public, max-age=300', $page->getCacheControlHeader());
    }

    /**
     * Check the header an inheriting child shows is the site-wide header while the switch is off
     */
    public function testInheritingChildHeaderIsTheSiteHeaderWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();

        $page = $this->objFromFixture(SiteTree::class, 'child_page');

        $this->assertSame('public, max-age=300', $page->getCacheControlHeader());
    }

    /**
     * Check the description names the site-wide settings as the source while the switch is off
     */
    public function testDescriptionNamesSiteSettingsWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();

        $description = $this->objFromFixture(SiteTree::class, 'override_page')
            ->getEffectiveCacheControlDescription();

        $this->assertStringContainsString('site-wide settings', $description);
        $this->assertStringNotContainsString('page-specific setting', $description);
    }

    /**
     * Check the description reports no cache control set when the site is off and the page switch is off
     */
    public function testDescriptionIgnoresPageDisableWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = false;
        $siteConfig->write();
        $page = $this->objFromFixture(SiteTree::class, 'override_page');
        $page->EnableCacheControl = false;

        $description = $page->getEffectiveCacheControlDescription();

        $this->assertStringContainsString('No cache control is currently set for this page.', $description);
        $this->assertStringNotContainsString('disabled for this specific page', $description);
    }

    /**
     * Check the runtime caveat shows for a saved override that is ignored while the switch is off
     */
    public function testDescriptionShowsCaveatForIgnoredOverrideWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();
        $page = $this->objFromFixture(SiteTree::class, 'override_page');
        $page->EnableCacheControl = false;

        $description = $page->getEffectiveCacheControlDescription();

        $this->assertStringContainsString('Silverstripe may override this at runtime', $description);
    }

    /**
     * Check the page settings are on unless a project turns them off
     */
    public function testSwitchIsOnByDefault(): void
    {
        $default = (new ReflectionProperty(CacheControlPageExtension::class, 'enable_cache_control_page_settings'))
            ->getDefaultValue();

        $this->assertTrue($default);
    }

    /**
     * Check a saved override with an invalid custom max age does not block a write while the switch is off
     */
    public function testInvalidSavedOverrideDoesNotBlockValidationWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();
        $page = $this->objFromFixture(SiteTree::class, 'override_page');
        $page->MaxAgePreset = 'custom';
        $page->MaxAge = 0;

        $this->assertTrue($page->validate()->isValid());
    }

    /**
     * Check the control case: the same invalid override fails validation while the switch is on
     */
    public function testInvalidOverrideFailsValidationWhileSwitchIsOn(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'override_page');
        $page->MaxAgePreset = 'custom';
        $page->MaxAge = 0;

        $this->assertFalse($page->validate()->isValid());
    }

    /**
     * Check draft cache reduction still applies while the switch is off
     */
    public function testDraftReductionStillAppliesWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();
        $page = $this->objFromFixture(SiteTree::class, 'override_page');
        $page->HasPendingDraftChanges = true;

        $middleware = $this->initialise($page);

        $this->assertSame(HTTPCacheControlMiddleware::STATE_PUBLIC, $middleware->getState());
        $this->assertSame(10, (int)$middleware->getDirective('max-age'));
    }

    /**
     * Check turning the switch back on restores the saved override for the same page
     */
    public function testTurningSwitchBackOnRestoresTheSavedOverride(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'override_page');

        $this->turnOffForAllPages();
        $this->assertSame('public, max-age=300', $page->getCacheControlHeader());

        Config::modify()->set(SiteTree::class, 'enable_cache_control_page_settings', true);
        $this->assertSame('private, max-age=600', $page->getCacheControlHeader());
        $this->assertTrue((bool)$page->OverrideCacheControl);
    }

    /**
     * Check a child stops inheriting from an ancestor whose own page class has the switch off
     */
    public function testChildStopsInheritingFromAncestorWhoseClassHasSwitchOff(): void
    {
        $child = $this->objFromFixture(SiteTree::class, 'redirector_grandchild');

        $this->assertSame('private, max-age=600', $child->getCacheControlHeader());

        Config::modify()->set(RedirectorPage::class, 'enable_cache_control_page_settings', false);

        $this->assertSame('public, max-age=300', $child->getCacheControlHeader());
        $middleware = $this->initialise($child);
        $this->assertSame(HTTPCacheControlMiddleware::STATE_PUBLIC, $middleware->getState());
        $this->assertSame(300, (int)$middleware->getDirective('max-age'));
    }

    /**
     * Check a page whose own class has the switch off does not inherit from an ancestor that has it on
     */
    public function testPageWhoseClassHasSwitchOffDoesNotInherit(): void
    {
        $child = $this->objFromFixture(SiteTree::class, 'child_page')
            ->newClassInstance(RedirectorPage::class);
        Config::modify()->set(RedirectorPage::class, 'enable_cache_control_page_settings', false);

        $this->assertNull($child->findInheritedCacheSource());
    }

    /**
     * Check the control case: the same page inherits from its ancestor while its class has the switch on
     */
    public function testPageWhoseClassHasSwitchOnInherits(): void
    {
        $child = $this->objFromFixture(SiteTree::class, 'child_page')
            ->newClassInstance(RedirectorPage::class);

        $this->assertNotNull($child->findInheritedCacheSource());
    }

    /**
     * Check the saved override data is never modified by building the form or running the controller
     */
    public function testSavedOverrideDataIsUntouchedWhileSwitchIsOff(): void
    {
        $this->turnOffForAllPages();
        $page = $this->objFromFixture(SiteTree::class, 'override_page');

        $page->getCMSFields();
        $this->initialise($page);

        $reloaded = SiteTree::get()->byID($page->ID);
        $this->assertTrue((bool)$reloaded->OverrideCacheControl);
        $this->assertTrue((bool)$reloaded->EnableCacheControl);
        $this->assertSame('private', $reloaded->CacheType);
        $this->assertSame('600', $reloaded->MaxAgePreset);
    }
}
