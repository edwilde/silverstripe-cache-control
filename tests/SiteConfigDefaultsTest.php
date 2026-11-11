<?php

namespace Edwilde\CacheControl\Tests;

use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControl\SiteConfigDefaults;
use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use Symfony\Component\Yaml\Yaml;

class SiteConfigDefaultsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
    ];

    /**
     * Start every test with no defaults configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        DefaultsConfig::set(0, []);
    }

    /**
     * Check the extension declares no defaults of its own
     */
    public function testModuleShipsNoDefaults(): void
    {
        $this->assertSame(
            0,
            Config::inst()->get(CacheControlSiteConfigExtension::class, 'cache_control_defaults_version', Config::UNINHERITED)
        );
        $this->assertSame(
            [],
            Config::inst()->get(CacheControlSiteConfigExtension::class, 'cache_control_defaults', Config::UNINHERITED)
        );
    }

    /**
     * Check the module config sets only the extensions key on SiteConfig, leaving the defaults keys to a project
     */
    public function testModuleConfigSetsOnlyTheExtensionsOnSiteConfig(): void
    {
        $keys = [];
        foreach ($this->moduleConfigDocuments() as $document) {
            $keys = array_merge($keys, array_keys($document[SiteConfig::class] ?? []));
        }

        $this->assertSame(['extensions'], $keys);
    }

    /**
     * Parse each YAML document of the module config file, since Symfony Yaml reads one document at a time
     *
     * @return array<int, array<string, mixed>>
     */
    private function moduleConfigDocuments(): array
    {
        $contents = (string)file_get_contents(dirname(__DIR__) . '/_config/config.yml');

        $documents = [];
        foreach (preg_split('/^---[ \t]*$/m', $contents) as $part) {
            $document = Yaml::parse($part);
            if (is_array($document)) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * Check an unknown key throws and the message names it
     */
    public function testUnknownKeyThrowsAndNamesTheKey(): void
    {
        DefaultsConfig::set(1, ['NotARealField' => 'x']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NotARealField');

        SiteConfigDefaults::configuredDefaults();
    }

    /**
     * Check the version field cannot be set through the map
     */
    public function testVersionFieldIsRejectedAsAKey(): void
    {
        DefaultsConfig::set(1, ['CacheControlDefaultsVersion' => 5]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CacheControlDefaultsVersion');

        SiteConfigDefaults::configuredDefaults();
    }

    /**
     * Check a core SiteConfig field the module does not own throws and the message names it
     */
    public function testCoreSiteConfigFieldIsRejectedAsAKey(): void
    {
        DefaultsConfig::set(1, ['Title' => 'Renamed']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown key "Title"');

        SiteConfigDefaults::configuredDefaults();
    }

    /**
     * Check every cache control field of the SiteConfig extension is accepted as a key
     */
    public function testEveryCacheControlFieldIsAnAllowedKey(): void
    {
        $defaults = [
            'EnableCacheControl' => true,
            'CacheType' => 'private',
            'CacheDuration' => 'maxage',
            'MaxAge' => 90,
            'MaxAgePreset' => 'custom',
            'SharedMaxAgePreset' => '300',
            'SharedMaxAge' => 600,
            'EnableMustRevalidate' => false,
            'StaleWhileRevalidatePreset' => '300',
            'StaleWhileRevalidate' => 120,
            'StaleIfErrorPreset' => '3600',
            'StaleIfError' => 7200,
            'VaryAcceptEncoding' => false,
            'VaryXForwardedProtocol' => true,
            'VaryCookie' => true,
            'VaryAuthorization' => true,
            'EnableDraftCacheReduction' => false,
        ];
        DefaultsConfig::set(1, $defaults);

        $this->assertSame($defaults, SiteConfigDefaults::configuredDefaults());
    }

    /**
     * Check the configured version is read as an integer
     */
    public function testConfiguredVersionIsAnInteger(): void
    {
        Config::modify()->set(SiteConfig::class, 'cache_control_defaults_version', '3');

        $this->assertSame(3, SiteConfigDefaults::configuredVersion());
    }

    /**
     * Check a positive version with a non-empty map is configured
     */
    public function testPositiveVersionWithAMapIsConfigured(): void
    {
        DefaultsConfig::set(1, ['CacheType' => 'private']);

        $this->assertTrue(SiteConfigDefaults::isConfigured());
    }

    /**
     * Check a version below one is not configured whatever the map holds
     */
    public function testVersionBelowOneIsNotConfigured(): void
    {
        DefaultsConfig::set(0, ['CacheType' => 'private']);
        $this->assertFalse(SiteConfigDefaults::isConfigured());

        DefaultsConfig::set(-1, ['CacheType' => 'private']);
        $this->assertFalse(SiteConfigDefaults::isConfigured());
    }

    /**
     * Check an empty map is not configured whatever the version
     */
    public function testEmptyMapIsNotConfigured(): void
    {
        DefaultsConfig::set(2, []);

        $this->assertFalse(SiteConfigDefaults::isConfigured());
    }

    /**
     * Check an unknown key does not throw while nothing is configured
     */
    public function testUnknownKeyIsIgnoredAtVersionZero(): void
    {
        DefaultsConfig::set(0, ['NotARealField' => 'x']);

        $this->assertFalse(SiteConfigDefaults::isConfigured());
    }

    /**
     * Check a row is due only while its stored version is lower than the configured one
     */
    public function testRowIsDueOnlyWhileItsStoredVersionIsLower(): void
    {
        $row = SiteConfig::create();
        DefaultsConfig::set(2, ['CacheType' => 'private']);

        $row->CacheControlDefaultsVersion = 0;
        $this->assertTrue(SiteConfigDefaults::isDue($row));

        $row->CacheControlDefaultsVersion = 1;
        $this->assertTrue(SiteConfigDefaults::isDue($row));

        $row->CacheControlDefaultsVersion = 2;
        $this->assertFalse(SiteConfigDefaults::isDue($row));

        $row->CacheControlDefaultsVersion = 3;
        $this->assertFalse(SiteConfigDefaults::isDue($row));
    }

    /**
     * Check an integer value for an enum field is assigned as a string the enum validator accepts
     */
    public function testIntegerEnumValueIsAssignedAsString(): void
    {
        $row = SiteConfig::create();
        DefaultsConfig::set(1, ['MaxAgePreset' => 300]);

        SiteConfigDefaults::assign($row);

        $this->assertSame('300', $row->MaxAgePreset);
        $this->assertTrue($row->validate()->isValid());
    }

    /**
     * Check values for fields that are not enums keep the type they were configured with
     */
    public function testNonEnumValuesAreAssignedAsGiven(): void
    {
        $row = SiteConfig::create();
        DefaultsConfig::set(1, ['EnableCacheControl' => true, 'MaxAge' => 600]);

        SiteConfigDefaults::assign($row);

        $this->assertTrue($row->EnableCacheControl);
        $this->assertSame(600, $row->MaxAge);
    }

    /**
     * Check assign sets the listed fields and the version and leaves every other field alone
     */
    public function testAssignSetsListedFieldsAndVersionOnly(): void
    {
        $row = SiteConfig::create();
        $row->VaryCookie = true;
        DefaultsConfig::set(3, ['CacheType' => 'private']);

        SiteConfigDefaults::assign($row);

        $this->assertSame('private', $row->CacheType);
        $this->assertSame(3, $row->CacheControlDefaultsVersion);
        $this->assertTrue($row->VaryCookie);
        $this->assertSame('120', $row->MaxAgePreset);
    }

    /**
     * Check assign changes nothing while the version is zero
     */
    public function testAssignDoesNothingAtVersionZero(): void
    {
        $row = SiteConfig::create();
        DefaultsConfig::set(0, ['CacheType' => 'private']);

        SiteConfigDefaults::assign($row);

        $this->assertSame('public', $row->CacheType);
    }

    /**
     * Check assign changes nothing while the map is empty
     */
    public function testAssignDoesNothingWithAnEmptyMap(): void
    {
        $row = SiteConfig::create();
        DefaultsConfig::set(2, []);

        SiteConfigDefaults::assign($row);

        $this->assertSame(0, $row->CacheControlDefaultsVersion);
    }

    /**
     * Check assign leaves the database untouched
     */
    public function testAssignDoesNotWriteTheRow(): void
    {
        $row = SiteConfig::create();
        DefaultsConfig::set(1, ['CacheType' => 'private']);

        SiteConfigDefaults::assign($row);

        $this->assertFalse($row->isInDB());
        $this->assertSame(0, SiteConfig::get()->count());
    }
}
