<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlContentControllerExtension;
use Edwilde\CacheControl\Extensions\CacheControlPageExtension;
use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControl\Tests\DefaultsConfig;
use ReflectionProperty;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\DBSchemaManager;
use SilverStripe\ORM\DB;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlSiteSettingsToggleTest extends SapphireTest
{
    protected static $fixture_file = 'CacheControlSiteSettingsToggleTest.yml';

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
     * Every field name the module adds to the site settings
     */
    private const MODULE_FIELDS = [
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
        'VaryAcceptEncoding',
        'VaryXForwardedProtocol',
        'VaryCookie',
        'VaryAuthorization',
        'EnableDraftCacheReduction',
        'CacheControlDefaultsVersion',
        'CacheControlHeader',
        'CacheControlInfo',
        'CacheControlSettings',
        'VarySettings',
    ];

    /**
     * The schema manager's output suppression before the test unquieted it
     */
    private ?bool $originalSuppressOutput = null;

    /**
     * Unquiet the schema manager, give the middleware production-like defaults and set every config the tests use
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSuppressOutput = $this->suppressOutputProperty()->getValue(DB::get_schema());
        DB::quiet(false);

        HTTPCacheControlMiddleware::config()
            ->set('defaultState', HTTPCacheControlMiddleware::STATE_ENABLED)
            ->set('defaultForcingLevel', 0);
        HTTPCacheControlMiddleware::reset();

        Config::modify()->set(SiteTree::class, 'enable_cache_inheritance', false);
        Config::modify()->set(SiteTree::class, 'enable_cache_control_page_settings', true);
        Config::modify()->set(SiteConfig::class, 'enable_cache_control_site_settings', true);
        DefaultsConfig::set(0, []);
    }

    /**
     * Put the schema manager's output suppression back to what it was before the test
     */
    protected function tearDown(): void
    {
        if ($this->originalSuppressOutput !== null) {
            $this->suppressOutputProperty()->setValue(DB::get_schema(), $this->originalSuppressOutput);
        }

        parent::tearDown();
    }

    /**
     * Get the schema manager property that silences alteration messages
     */
    private function suppressOutputProperty(): ReflectionProperty
    {
        return new ReflectionProperty(DBSchemaManager::class, 'supressOutput');
    }

    /**
     * Turn the site settings off
     */
    private function turnOffSiteSettings(): void
    {
        Config::modify()->set(SiteConfig::class, 'enable_cache_control_site_settings', false);
    }

    /**
     * Run the default-records step of dev/build and return what it printed
     */
    private function build(): string
    {
        ob_start();
        try {
            SiteConfig::singleton()->requireDefaultRecords();
        } finally {
            $output = (string)ob_get_clean();
        }

        return $output;
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
     * Check the site settings tab and fields exist while the switch is on
     */
    public function testFieldsPresentWhileSwitchIsOn(): void
    {
        $fields = SiteConfig::current_site_config()->getCMSFields();

        $this->assertNotNull($fields->fieldByName('Root.CacheControl'));
        $this->assertNotNull($fields->fieldByName('Root.CacheControl.CacheControlHeader'));
    }

    /**
     * Check no module field, heading or tab is built while the switch is off
     */
    public function testNoFieldsBuiltWhileSwitchIsOff(): void
    {
        $this->turnOffSiteSettings();

        $fields = SiteConfig::current_site_config()->getCMSFields();

        $this->assertNull($fields->fieldByName('Root.CacheControl')?->getName());
        $this->assertNull($fields->fieldByName('CacheControlHeader')?->getName());
        foreach (self::MODULE_FIELDS as $name) {
            $this->assertNull($fields->dataFieldByName($name)?->getName(), $name . ' should not be in the form');
            $this->assertNull($fields->fieldByName($name)?->getName(), $name . ' should not be in the form');
        }
    }

    /**
     * Check pages still follow the stored site config while the switch is off
     */
    public function testHeadersFollowStoredSiteConfigWhileSwitchIsOff(): void
    {
        $this->turnOffSiteSettings();

        $middleware = $this->initialise($this->objFromFixture(SiteTree::class, 'plain_page'));

        $this->assertSame(HTTPCacheControlMiddleware::STATE_PUBLIC, $middleware->getState());
        $this->assertSame(300, (int)$middleware->getDirective('max-age'));
    }

    /**
     * Check pages follow the stored site config when both switches are off, ignoring a saved page override
     */
    public function testHeadersFollowStoredSiteConfigWhileBothSwitchesAreOff(): void
    {
        $this->turnOffSiteSettings();
        Config::modify()->set(SiteTree::class, 'enable_cache_control_page_settings', false);

        $middleware = $this->initialise($this->objFromFixture(SiteTree::class, 'override_page'));

        $this->assertSame(HTTPCacheControlMiddleware::STATE_PUBLIC, $middleware->getState());
        $this->assertSame(300, (int)$middleware->getDirective('max-age'));
    }

    /**
     * Check the build warns about each disabled row while the switch is off, and names only that row
     */
    public function testBuildWarnsForDisabledRowWhileSwitchIsOff(): void
    {
        $this->turnOffSiteSettings();
        $disabledId = $this->idFromFixture(SiteConfig::class, 'disabled_site');

        $output = $this->build();

        $this->assertMatchesRegularExpression(
            '/^ {2}! .*#' . $disabledId . '\b.*Disabled site.*no-store.*cache_control_defaults and raise cache_control_defaults_version/m',
            $output
        );
        $this->assertStringNotContainsString('Enabled public site', $output);
    }

    /**
     * Check the build prints no warning while the switch is on
     */
    public function testBuildDoesNotWarnWhileSwitchIsOn(): void
    {
        $output = $this->build();

        $this->assertStringNotContainsString('no-store', $output);
    }

    /**
     * Check the build prints no warning when the defaults enable every row in the same build
     */
    public function testBuildDoesNotWarnWhenDefaultsEnableTheRow(): void
    {
        $this->turnOffSiteSettings();
        DefaultsConfig::set(1, ['EnableCacheControl' => true]);

        $output = $this->build();

        $this->assertStringContainsString('Applied cache control defaults version 1', $output);
        $this->assertStringNotContainsString('no-store', $output);
    }

    /**
     * Check the site settings are on unless a project turns them off
     */
    public function testSwitchIsOnByDefault(): void
    {
        $default = (new ReflectionProperty(CacheControlSiteConfigExtension::class, 'enable_cache_control_site_settings'))
            ->getDefaultValue();

        $this->assertTrue($default);
    }
}
