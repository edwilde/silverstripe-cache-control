<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlContentControllerExtension;
use Edwilde\CacheControl\Extensions\CacheControlPageExtension;
use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlContentControllerExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'CacheControlContentControllerExtensionTest.yml';

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

    protected function setUp(): void
    {
        parent::setUp();

        // Override dev environment config that sets defaultState=disabled and
        // defaultForcingLevel=3 — we need production-like defaults to test
        // the middleware priority system accurately.
        HTTPCacheControlMiddleware::config()
            ->set('defaultState', HTTPCacheControlMiddleware::STATE_ENABLED)
            ->set('defaultForcingLevel', 0);
        HTTPCacheControlMiddleware::reset();
    }

    protected function getMiddleware(): HTTPCacheControlMiddleware
    {
        return HTTPCacheControlMiddleware::singleton();
    }

    /**
     * Core regression test: publicCache() must not prevent Form's disableCache() from working.
     * Previously publicCache(true) set forcing level 11, blocking disableCache() at level 3.
     */
    public function testPublicCacheDoesNotPreventFormDisableCache()
    {
        $middleware = $this->getMiddleware();

        // Simulate what the extension does for a public cache page
        $middleware->publicCache();
        $middleware->setMaxAge(300);

        // Simulate what Form::forTemplate() does for forms with CSRF tokens
        $middleware->disableCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'Form disableCache() should override non-forced publicCache()'
        );
    }

    /**
     * Session detection must be able to downgrade public to private.
     * Previously publicCache(true) blocked the session middleware's privateCache() call.
     */
    public function testPublicCacheDoesNotPreventSessionPrivateCache()
    {
        $middleware = $this->getMiddleware();

        // Simulate what the extension does for a public cache page
        $middleware->publicCache();
        $middleware->setMaxAge(300);

        // Simulate what HTTPCacheControlMiddleware::augmentState() does when sessions exist
        $middleware->privateCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_PRIVATE,
            $middleware->getState(),
            'Session privateCache() should override non-forced publicCache()'
        );
    }

    /**
     * Private cache must still allow forms to disable caching entirely.
     */
    public function testPrivateCacheDoesNotPreventFormDisableCache()
    {
        $middleware = $this->getMiddleware();

        // Simulate what the extension does for a private cache page
        $middleware->privateCache();
        $middleware->setMaxAge(300);

        // Simulate what Form::forTemplate() does
        $middleware->disableCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'Form disableCache() should override non-forced privateCache()'
        );
    }

    /**
     * When a CMS user explicitly disables caching, that forced disable must not be overridden.
     */
    public function testForcedDisableCacheCannotBeOverridden()
    {
        $middleware = $this->getMiddleware();

        // Simulate what the extension does when EnableCacheControl is false
        $middleware->disableCache(true);

        // Attempt to set public cache (non-forced, as other code might do)
        $middleware->publicCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'Forced disableCache() should not be overridden by non-forced publicCache()'
        );
    }

    /**
     * Integration test: page-level public cache setting should use non-forced calls,
     * allowing Silverstripe's form/session protection to still work.
     */
    public function testApplyPageSettingsUsesNonForcedPublicCache()
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '300';
        $page->EnableMustRevalidate = false;
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();

        // Simulate what Form::forTemplate() does — this must win
        $middleware->disableCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'disableCache() should override page-level publicCache() set by the extension'
        );
    }

    /**
     * Integration test: site-level public cache setting should use non-forced calls,
     * allowing Silverstripe's form/session protection to still work.
     */
    public function testApplySiteSettingsUsesNonForcedPublicCache()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        // Page with no override — falls through to site settings
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = false;
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();

        // Simulate what Form::forTemplate() does — this must win
        $middleware->disableCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'disableCache() should override site-level publicCache() set by the extension'
        );
    }

    /**
     * Integration test: child page without override should inherit cache settings
     * from parent with ApplyCacheToChildren enabled (when config allows).
     */
    public function testApplyInheritedSettingsFromParent()
    {
        SiteTree::config()->set('enable_cache_inheritance', true);

        $child = $this->objFromFixture(SiteTree::class, 'archive_child');

        $controller = ContentController::create($child);
        $controller->doInit();

        $middleware = $this->getMiddleware();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_PUBLIC,
            $middleware->getState(),
            'Child should inherit public cache from parent with ApplyCacheToChildren'
        );
    }

    /**
     * Integration test: child page with own override should use its own settings,
     * not inherit from parent with ApplyCacheToChildren.
     */
    public function testChildOverrideIgnoresParentApplyCacheToChildren()
    {
        SiteTree::config()->set('enable_cache_inheritance', true);

        $child = $this->objFromFixture(SiteTree::class, 'archive_child_with_override');

        $controller = ContentController::create($child);
        $controller->doInit();

        $middleware = $this->getMiddleware();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_PRIVATE,
            $middleware->getState(),
            'Child with own override should use private, not inherit public from parent'
        );
    }

    /**
     * Integration test: inherited cache settings should still allow Form's
     * disableCache() to take precedence (non-forced middleware calls).
     */
    public function testApplyInheritedSettingsUsesNonForcedCalls()
    {
        SiteTree::config()->set('enable_cache_inheritance', true);

        $child = $this->objFromFixture(SiteTree::class, 'archive_child');

        $controller = ContentController::create($child);
        $controller->doInit();

        $middleware = $this->getMiddleware();

        // Simulate Form::forTemplate() — this must win
        $middleware->disableCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'disableCache() should override inherited publicCache() set by the extension'
        );
    }

    /**
     * Integration test: when config is disabled, child should fall back to site config
     * even when parent has ApplyCacheToChildren.
     */
    public function testNoInheritanceWhenConfigDisabled()
    {
        SiteTree::config()->set('enable_cache_inheritance', false);

        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'private';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $child = $this->objFromFixture(SiteTree::class, 'archive_child');

        $controller = ContentController::create($child);
        $controller->doInit();

        $middleware = $this->getMiddleware();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_PRIVATE,
            $middleware->getState(),
            'Should use site config (private) not parent (public) when config disabled'
        );
    }

    public function testDraftPageGetsReducedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->HasPendingDraftChanges = true;

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(10, $middleware->getDirective('max-age'),
            'Max-age should be reduced to 10 for page with pending draft changes');
    }

    public function testPublishedPageKeepsNormalMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        // No draft changes — HasPendingDraftChanges stays false

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(3600, $middleware->getDirective('max-age'),
            'Max-age should remain 3600 for published page without draft changes');
    }

    public function testDraftReductionDisabledViaSiteConfig()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = false;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->HasPendingDraftChanges = true;

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(3600, $middleware->getDirective('max-age'),
            'Max-age should remain 3600 when draft reduction is disabled');
    }

    public function testDraftReductionWorksWithPageOverride()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '86400';
        $page->EnableMustRevalidate = false;
        $page->HasPendingDraftChanges = true;
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(10, $middleware->getDirective('max-age'),
            'Max-age should be reduced to 10 even with page override of 86400');
    }

    public function testDraftReductionSkippedWhenCacheDisabled()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = false;
        $page->HasPendingDraftChanges = true;
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'Cache should remain disabled, not reduced to 10s'
        );
    }

    public function testCustomDraftMaxAgeViaConfig()
    {
        SiteTree::config()->set('draft_cache_max_age', 30);

        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->HasPendingDraftChanges = true;

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(30, $middleware->getDirective('max-age'),
            'Max-age should be 30 from custom config, not default 10');
    }

    public function testDraftReductionStillAllowsFormDisableCache()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->EnableMustRevalidate = false;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->HasPendingDraftChanges = true;

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $middleware->disableCache();

        $this->assertEquals(
            HTTPCacheControlMiddleware::STATE_DISABLED,
            $middleware->getState(),
            'Form disableCache() should still override draft-reduced cache'
        );
    }

    public function testDraftReductionWithInheritedCache()
    {
        SiteTree::config()->set('enable_cache_inheritance', true);

        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->write();

        $child = $this->objFromFixture(SiteTree::class, 'archive_child');
        $child->HasPendingDraftChanges = true;

        $controller = ContentController::create($child);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(10, $middleware->getDirective('max-age'),
            'Inherited cache should still be reduced when child has draft changes');
    }
}
