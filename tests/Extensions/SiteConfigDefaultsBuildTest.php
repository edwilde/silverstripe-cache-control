<?php

namespace Edwilde\CacheControl\Tests\Extensions;

use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use Edwilde\CacheControl\SiteConfigDefaults;
use InvalidArgumentException;
use ReflectionProperty;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\DBSchemaManager;
use SilverStripe\ORM\DB;
use SilverStripe\SiteConfig\SiteConfig;

class SiteConfigDefaultsBuildTest extends SapphireTest
{
    protected static $fixture_file = 'SiteConfigDefaultsBuildTest.yml';

    protected static $required_extensions = [
        SiteConfig::class => [
            CacheControlSiteConfigExtension::class,
        ],
    ];

    /**
     * The configured field map used unless a test needs another
     *
     * MaxAgePreset is an integer, as YAML reads it, so each build exercises the enum string cast.
     */
    private const DEFAULTS = [
        'EnableCacheControl' => true,
        'CacheType' => 'public',
        'MaxAgePreset' => 300,
        'EnableMustRevalidate' => false,
    ];

    /**
     * The configured field map for new rows, where every value differs from the field default
     */
    private const NEW_ROW_DEFAULTS = [
        'EnableCacheControl' => true,
        'CacheType' => 'private',
        'MaxAgePreset' => 300,
        'EnableMustRevalidate' => false,
    ];

    /**
     * The schema manager's output suppression before the test unquieted it
     */
    private ?bool $originalSuppressOutput = null;

    /**
     * Unquiet the schema manager so the alteration messages of a build can be captured
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSuppressOutput = $this->suppressOutputProperty()->getValue(DB::get_schema());
        DB::quiet(false);
    }

    /**
     * Put the schema manager's output suppression back to what it was before the test
     */
    protected function tearDown(): void
    {
        $this->suppressOutputProperty()->setValue(DB::get_schema(), $this->originalSuppressOutput);

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
     * Set the configured defaults version and field map for the current test
     *
     * @param int $version The configured defaults version
     * @param array<string, mixed> $defaults The configured field map
     * @return void
     */
    private function configure(int $version, array $defaults): void
    {
        Config::modify()->set(SiteConfig::class, 'cache_control_defaults_version', $version);
        Config::modify()->set(SiteConfig::class, 'cache_control_defaults', $defaults);
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
     * Load a fixture row fresh from the database
     */
    private function reload(string $fixtureName): SiteConfig
    {
        return SiteConfig::get()->byID($this->idFromFixture(SiteConfig::class, $fixtureName));
    }

    /**
     * Check a row below the configured version gets every listed field, overwriting its value, and the version
     */
    public function testDueRowReceivesEveryListedFieldAndTheVersion(): void
    {
        $this->configure(2, self::DEFAULTS);

        $this->build();

        $row = $this->reload('first_stale');
        $this->assertTrue((bool)$row->EnableCacheControl);
        $this->assertSame('public', $row->CacheType);
        $this->assertSame('300', $row->MaxAgePreset);
        $this->assertFalse((bool)$row->EnableMustRevalidate);
        $this->assertSame(2, (int)$row->CacheControlDefaultsVersion);
    }

    /**
     * Check a build leaves the fields the map does not list as they were
     */
    public function testUnlistedFieldsKeepTheirValues(): void
    {
        $this->configure(2, self::DEFAULTS);

        $this->build();

        $row = $this->reload('first_stale');
        $this->assertSame(2, (int)$row->CacheControlDefaultsVersion);
        $this->assertTrue((bool)$row->VaryCookie);
        $this->assertSame(600, (int)$row->MaxAge);
    }

    /**
     * Check every row below the configured version is updated
     */
    public function testEveryRowBelowTheConfiguredVersionIsUpdated(): void
    {
        $this->configure(2, self::DEFAULTS);

        $this->build();

        foreach (['first_stale', 'second_stale'] as $fixtureName) {
            $row = $this->reload($fixtureName);
            $this->assertSame('public', $row->CacheType, $fixtureName);
            $this->assertSame(2, (int)$row->CacheControlDefaultsVersion, $fixtureName);
        }
    }

    /**
     * Check a second build at the same version keeps a field an editor changed after the first
     */
    public function testRebuildAtTheSameVersionKeepsAnEditorChange(): void
    {
        $this->configure(2, self::DEFAULTS);
        $this->build();
        $row = $this->reload('first_stale');
        $this->assertSame('public', $row->CacheType);
        $row->CacheType = 'private';
        $row->write();

        $output = $this->build();

        $this->assertSame('private', $this->reload('first_stale')->CacheType);
        $this->assertSame(2, (int)$this->reload('first_stale')->CacheControlDefaultsVersion);
        $this->assertStringNotContainsString('Applied', $output);
    }

    /**
     * Check a row above the configured version is untouched while a lower one is updated
     */
    public function testRowWithAHigherVersionIsUntouched(): void
    {
        $this->configure(2, self::DEFAULTS);

        $this->build();

        $this->assertSame(2, (int)$this->reload('first_stale')->CacheControlDefaultsVersion);
        $ahead = $this->reload('ahead');
        $this->assertSame(5, (int)$ahead->CacheControlDefaultsVersion);
        $this->assertFalse((bool)$ahead->EnableCacheControl);
        $this->assertSame('private', $ahead->CacheType);
        $this->assertSame('3600', $ahead->MaxAgePreset);
    }

    /**
     * Check a build at version zero changes nothing, and the same rows update once a version is set
     */
    public function testVersionZeroChangesNothing(): void
    {
        $this->configure(0, self::DEFAULTS);

        $output = $this->build();

        $this->assertStringNotContainsString('Applied', $output);
        $this->assertSame(0, (int)$this->reload('first_stale')->CacheControlDefaultsVersion);
        $this->assertSame('private', $this->reload('first_stale')->CacheType);

        $this->configure(2, self::DEFAULTS);
        $this->build();

        $this->assertSame('public', $this->reload('first_stale')->CacheType);
    }

    /**
     * Check a build with an empty map stamps no version and prints nothing, and the same rows update once a map is set
     */
    public function testEmptyMapChangesNothing(): void
    {
        $this->configure(2, []);

        $output = $this->build();

        $this->assertStringNotContainsString('Applied', $output);
        $this->assertSame(0, (int)$this->reload('first_stale')->CacheControlDefaultsVersion);

        $this->configure(2, self::DEFAULTS);
        $this->build();

        $this->assertSame(2, (int)$this->reload('first_stale')->CacheControlDefaultsVersion);
    }

    /**
     * Check one invalid row fails the build with its ID and message and no row is written
     */
    public function testInvalidRowFailsTheBuildBeforeAnyRowIsWritten(): void
    {
        $this->configure(2, ['EnableCacheControl' => true, 'MaxAgePreset' => 'custom']);

        $firstPreview = $this->reload('first_stale');
        SiteConfigDefaults::assign($firstPreview);
        $this->assertTrue($firstPreview->validate()->isValid(), 'The first row is valid with the map assigned');

        $message = null;
        try {
            $this->build();
            $this->fail('The build should have thrown for the second row');
        } catch (ValidationException $exception) {
            $message = $exception->getMessage();
        }

        $secondId = $this->idFromFixture(SiteConfig::class, 'second_stale');
        $this->assertStringContainsString('SiteConfig #' . $secondId, $message);
        $this->assertStringContainsString('Custom max age must be at least 1 second.', $message);
        $first = $this->reload('first_stale');
        $this->assertSame('3600', $first->MaxAgePreset);
        $this->assertSame(0, (int)$first->CacheControlDefaultsVersion);
        $this->assertSame(1, (int)$this->reload('second_stale')->CacheControlDefaultsVersion);
    }

    /**
     * Check an unknown key fails the build even when no row is due
     */
    public function testUnknownKeyFailsTheBuildWhenNoRowIsDue(): void
    {
        foreach (SiteConfig::get() as $row) {
            $row->CacheControlDefaultsVersion = 9;
            $row->write();
        }
        $this->configure(2, ['NotARealField' => 'x']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NotARealField');

        $this->build();
    }

    /**
     * Check the build prints a message naming each updated row and none for a row it skips
     */
    public function testAlterationMessageNamesEachUpdatedRow(): void
    {
        $this->configure(2, self::DEFAULTS);

        $output = $this->build();

        $firstId = $this->idFromFixture(SiteConfig::class, 'first_stale');
        $secondId = $this->idFromFixture(SiteConfig::class, 'second_stale');
        $aheadId = $this->idFromFixture(SiteConfig::class, 'ahead');
        $this->assertStringContainsString('Applied cache control defaults version 2 to SiteConfig #' . $firstId, $output);
        $this->assertStringContainsString('Applied cache control defaults version 2 to SiteConfig #' . $secondId, $output);
        $this->assertStringNotContainsString('SiteConfig #' . $aheadId, $output);
    }

    /**
     * Check a new row written after creation holds the configured fields and the version
     */
    public function testNewRowTakesTheDefaultsAndVersionWhenWritten(): void
    {
        $this->configure(2, self::NEW_ROW_DEFAULTS);

        $row = SiteConfig::create();
        $row->write();

        $reloaded = SiteConfig::get()->byID($row->ID);
        $this->assertTrue((bool)$reloaded->EnableCacheControl);
        $this->assertSame('private', $reloaded->CacheType);
        $this->assertSame('300', $reloaded->MaxAgePreset);
        $this->assertFalse((bool)$reloaded->EnableMustRevalidate);
        $this->assertSame(2, (int)$reloaded->CacheControlDefaultsVersion);
    }

    /**
     * Check a new row is not written by assigning the defaults
     */
    public function testNewRowIsNotWrittenByAssigningTheDefaults(): void
    {
        $this->configure(2, self::NEW_ROW_DEFAULTS);
        $rowCount = SiteConfig::get()->count();

        $row = SiteConfig::create();

        $this->assertSame('private', $row->CacheType);
        $this->assertFalse($row->isInDB());
        $this->assertSame($rowCount, SiteConfig::get()->count());
    }

    /**
     * Check a new row keeps the field defaults at version zero, and takes the map once a version is set
     */
    public function testNewRowIsLeftAloneAtVersionZero(): void
    {
        $this->configure(0, self::NEW_ROW_DEFAULTS);

        $untouched = SiteConfig::create();

        $this->assertSame('public', $untouched->CacheType);
        $this->assertSame(0, (int)$untouched->CacheControlDefaultsVersion);

        $this->configure(2, self::NEW_ROW_DEFAULTS);

        $this->assertSame('private', SiteConfig::create()->CacheType);
    }

    /**
     * Check a new row is not stamped with a version for an empty map, and takes the map once one is set
     */
    public function testNewRowIsLeftAloneWithAnEmptyMap(): void
    {
        $this->configure(2, []);

        $untouched = SiteConfig::create();

        $this->assertSame(0, (int)$untouched->CacheControlDefaultsVersion);

        $this->configure(2, self::NEW_ROW_DEFAULTS);

        $this->assertSame(2, (int)SiteConfig::create()->CacheControlDefaultsVersion);
    }

    /**
     * Check creating a row throws for an unknown key and names it
     */
    public function testUnknownKeyThrowsWhenARowIsCreated(): void
    {
        $this->configure(2, ['NotARealField' => 'x']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NotARealField');

        SiteConfig::create();
    }

    /**
     * Check a build with no SiteConfig row creates one holding the configured fields and the version
     */
    public function testFreshInstallBuildCreatesARowWithTheDefaultsAndVersion(): void
    {
        foreach (SiteConfig::get() as $row) {
            $row->delete();
        }
        $this->assertSame(0, SiteConfig::get()->count());
        $this->configure(2, self::NEW_ROW_DEFAULTS);

        $this->build();

        $this->assertSame(1, SiteConfig::get()->count());
        $created = SiteConfig::get()->first();
        $this->assertTrue((bool)$created->EnableCacheControl);
        $this->assertSame('private', $created->CacheType);
        $this->assertSame('300', $created->MaxAgePreset);
        $this->assertFalse((bool)$created->EnableMustRevalidate);
        $this->assertSame(2, (int)$created->CacheControlDefaultsVersion);
    }

    /**
     * Check a second build after a fresh install keeps an editor change and applies nothing
     */
    public function testSecondBuildAfterAFreshInstallChangesNothing(): void
    {
        foreach (SiteConfig::get() as $row) {
            $row->delete();
        }
        $this->configure(2, self::NEW_ROW_DEFAULTS);
        $this->build();
        $created = SiteConfig::get()->first();
        $this->assertSame('private', $created->CacheType);
        $created->CacheType = 'public';
        $created->write();

        $output = $this->build();

        $this->assertSame(1, SiteConfig::get()->count());
        $this->assertSame('public', SiteConfig::get()->first()->CacheType);
        $this->assertSame(2, (int)SiteConfig::get()->first()->CacheControlDefaultsVersion);
        $this->assertStringNotContainsString('Applied', $output);
    }
}
