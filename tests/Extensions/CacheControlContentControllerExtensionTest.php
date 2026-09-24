<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlContentControllerExtension;
use Edwilde\CacheControl\Extensions\CacheControlPageExtension;
use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControl\SharedMaxAge;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

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

    /**
     * Page with HasPendingDraftChanges=true should have its max-age reduced to 10s.
     */
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

    /**
     * Page without draft changes should keep the normal site config max-age.
     */
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

    /**
     * When EnableDraftCacheReduction is false in SiteConfig, draft pages keep normal max-age.
     */
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

    /**
     * Draft reduction should override a page-level max-age even when page has its own cache override.
     */
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

    /**
     * When page-level cache is explicitly disabled, draft reduction should not re-enable it.
     */
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

    /**
     * The draft_cache_max_age config should be respected when reducing max-age.
     */
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

    /**
     * Form's disableCache() should still override draft-reduced cache at higher forcing level.
     */
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

    /**
     * Draft reduction should apply even when the page uses inherited cache settings from an ancestor.
     */
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

    /**
     * Regression: with all Vary options unchecked, the framework's defaultVary
     * (X-Forwarded-Protocol) must not leak into the response. Previously the
     * extension skipped setVary() when no options were selected, so getVary()
     * fell back to the framework default.
     */
    public function testAllVaryOptionsUncheckedEmitsNoVary()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->VaryAcceptEncoding = false;
        $siteConfig->VaryXForwardedProtocol = false;
        $siteConfig->VaryCookie = false;
        $siteConfig->VaryAuthorization = false;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = false;
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(
            [],
            $middleware->getVary(),
            'No Vary should be emitted when all options are unchecked (framework default must be cleared)'
        );
    }

    /**
     * Selecting only Accept-Encoding must replace the framework default, so
     * X-Forwarded-Protocol does not appear alongside it.
     */
    public function testVaryReflectsSelectedOptionsOnly()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->VaryAcceptEncoding = true;
        $siteConfig->VaryXForwardedProtocol = false;
        $siteConfig->VaryCookie = false;
        $siteConfig->VaryAuthorization = false;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = false;
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(
            ['Accept-Encoding'],
            $middleware->getVary(),
            'Only the selected Vary option should be present, not the framework default'
        );
    }

    /**
     * Site-level grace periods reach the middleware for a page with no override.
     */
    public function testSiteSettingsEmitStaleWhileRevalidate()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->StaleWhileRevalidatePreset = '86400';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        ContentController::create($page)->doInit();

        $this->assertEquals(86400, $this->getMiddleware()->getDirective('stale-while-revalidate'));
    }

    /**
     * A page override emits both directives, and must-revalidate is withheld because it would
     * forbid the stale reuse the grace periods ask for.
     */
    public function testPageOverrideEmitsBothStaleDirectives()
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '120';
        $page->EnableMustRevalidate = true;
        $page->StaleWhileRevalidatePreset = '21600';
        $page->StaleIfErrorPreset = '604800';
        $page->write();

        ContentController::create($page)->doInit();
        $middleware = $this->getMiddleware();

        $this->assertEquals(21600, $middleware->getDirective('stale-while-revalidate'));
        $this->assertEquals(604800, $middleware->getDirective('stale-if-error'));
        $this->assertFalse($middleware->getDirective('must-revalidate'));
    }

    /**
     * The directives are set on the same states as max-age, so a session downgrading the
     * response from public to private keeps them.
     */
    public function testStaleDirectivesSurvivePrivateDowngrade()
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '120';
        $page->StaleWhileRevalidatePreset = '86400';
        $page->write();

        ContentController::create($page)->doInit();
        $middleware = $this->getMiddleware();
        $middleware->privateCache();

        $this->assertEquals(86400, $middleware->getDirective('stale-while-revalidate'));
    }

    /**
     * Nothing changes for a site that has not opted in.
     */
    public function testStaleDirectivesOffByDefault()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '120';
        $siteConfig->EnableMustRevalidate = true;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        ContentController::create($page)->doInit();
        $middleware = $this->getMiddleware();

        $this->assertFalse($middleware->getDirective('stale-while-revalidate'));
        $this->assertFalse($middleware->getDirective('stale-if-error'));
        $this->assertTrue($middleware->getDirective('must-revalidate'));
    }

    /**
     * A grace period of zero must not surface as "stale-while-revalidate=0".
     */
    public function testZeroGracePeriodIsNotEmitted()
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '120';
        $page->StaleWhileRevalidatePreset = 'custom';
        $page->StaleWhileRevalidate = 0;

        $controller = ContentController::create($page);
        $controller->doInit();

        $header = $this->getMiddleware()->generateHeadersFor($controller->getResponse())['Cache-Control'];
        $this->assertStringNotContainsString('stale-while-revalidate', $header);
    }

    public function testGeneratedHeaderStringContainsStaleDirectives()
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '120';
        $page->StaleWhileRevalidatePreset = '86400';
        $page->StaleIfErrorPreset = '604800';
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $header = $this->getMiddleware()->generateHeadersFor($controller->getResponse())['Cache-Control'];
        $this->assertMatchesRegularExpression('/stale-while-revalidate=86400/', $header);
        $this->assertMatchesRegularExpression('/stale-if-error=604800/', $header);
    }

    /**
     * Draft reduction lowers max-age only; the grace periods are left in place.
     */
    public function testDraftReductionKeepsStaleDirectives()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->StaleWhileRevalidatePreset = '86400';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->publishSingle();
        $page->Title = 'Draft Change';
        $page->write();

        $livePage = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE)->byID($page->ID);
        ContentController::create($livePage)->doInit();
        $middleware = $this->getMiddleware();

        $this->assertEquals(10, $middleware->getDirective('max-age'));
        $this->assertEquals(86400, $middleware->getDirective('stale-while-revalidate'));
    }

    /**
     * Site-level public cache with a CDN duration emits s-maxage after max-age.
     */
    public function testSiteSettingsEmitSharedMaxAgeAfterMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(604800, $middleware->getDirective('s-maxage'));

        $header = $middleware->generateHeadersFor($controller->getResponse())['Cache-Control'];
        $this->assertMatchesRegularExpression('/max-age=300.*s-maxage=604800/', $header);
    }

    /**
     * Private cache never emits s-maxage, even with a CDN duration configured.
     */
    public function testPrivateCacheEmitsNoSharedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'private';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        ContentController::create($page)->doInit();

        $this->assertFalse($this->getMiddleware()->getDirective('s-maxage'));
    }

    /**
     * A no-store cache duration emits no s-maxage.
     */
    public function testNoStoreEmitsNoSharedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'nostore';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        ContentController::create($page)->doInit();

        $this->assertFalse($this->getMiddleware()->getDirective('s-maxage'));
    }

    /**
     * A CDN duration left off removes any s-maxage set elsewhere before onAfterInit().
     */
    public function testSharedMaxAgeOffRemovesPreExistingDirective()
    {
        $middleware = $this->getMiddleware();
        $middleware->setSharedMaxAge(3600);

        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = SharedMaxAge::PRESET_OFF;
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        ContentController::create($page)->doInit();

        $this->assertFalse($middleware->getDirective('s-maxage'));
    }

    /**
     * A page override supplies its own CDN duration, not the site config's.
     */
    public function testPageOverrideUsesOwnSharedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = '86400';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '300';
        $page->SharedMaxAgePreset = '604800';
        $page->write();

        ContentController::create($page)->doInit();

        $this->assertEquals(604800, $this->getMiddleware()->getDirective('s-maxage'));
    }

    /**
     * An inherited ancestor's CDN duration reaches a child page with no own override.
     */
    public function testInheritedCacheSuppliesSharedMaxAge()
    {
        SiteTree::config()->set('enable_cache_inheritance', true);

        $archive = $this->objFromFixture(SiteTree::class, 'archive');
        $archive->SharedMaxAgePreset = '86400';
        $archive->write();

        $child = $this->objFromFixture(SiteTree::class, 'archive_child');
        ContentController::create($child)->doInit();

        $this->assertEquals(86400, $this->getMiddleware()->getDirective('s-maxage'));
    }

    /**
     * A session downgrade from public to private removes s-maxage set for the public state.
     */
    public function testSharedMaxAgeRemovedOnSessionPrivateDowngrade()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '300';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        ContentController::create($page)->doInit();

        $middleware = $this->getMiddleware();
        $middleware->privateCache();

        $this->assertFalse($middleware->getDirective('s-maxage'));
    }

    /**
     * Draft cache reduction caps s-maxage to the draft value alongside max-age.
     */
    public function testDraftReductionCapsSharedMaxAge()
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->EnableCacheControl = true;
        $siteConfig->EnableDraftCacheReduction = true;
        $siteConfig->CacheType = 'public';
        $siteConfig->CacheDuration = 'maxage';
        $siteConfig->MaxAgePreset = '3600';
        $siteConfig->SharedMaxAgePreset = '604800';
        $siteConfig->write();

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->HasPendingDraftChanges = true;

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(10, $middleware->getDirective('max-age'));
        $this->assertEquals(10, $middleware->getDirective('s-maxage'));
    }

    /**
     * s-maxage combines with grace periods, and must-revalidate is still omitted.
     */
    public function testSharedMaxAgeCombinesWithGracePeriods()
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page->OverrideCacheControl = true;
        $page->EnableCacheControl = true;
        $page->CacheType = 'public';
        $page->CacheDuration = 'maxage';
        $page->MaxAgePreset = '300';
        $page->SharedMaxAgePreset = '604800';
        $page->StaleWhileRevalidatePreset = '3600';
        $page->write();

        $controller = ContentController::create($page);
        $controller->doInit();

        $middleware = $this->getMiddleware();
        $this->assertEquals(604800, $middleware->getDirective('s-maxage'));
        $this->assertEquals(3600, $middleware->getDirective('stale-while-revalidate'));
        $this->assertFalse($middleware->getDirective('must-revalidate'));
    }
}
