<?php

declare(strict_types=1);

/**
 * Site Config Defaults Helper
 *
 * Reads the versioned, project-defined cache control policy from YAML config and assigns it to
 * SiteConfig rows whose stored defaults version is lower than the configured one.
 *
 * @package Edwilde\CacheControl
 * @author Ed Wilde
 */

namespace Edwilde\CacheControl;

use Edwilde\CacheControl\Extensions\CacheControlSiteConfigExtension;
use InvalidArgumentException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBEnum;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Resolves, validates and assigns the versioned site-wide cache control defaults.
 *
 * A project sets `cache_control_defaults_version` and `cache_control_defaults` on SiteConfig. The
 * defaults count as configured only with a version above zero and a non-empty map; anything else
 * leaves every row alone.
 */
final class SiteConfigDefaults
{
    /**
     * The SiteConfig field holding the defaults version last applied to a row.
     */
    public const VERSION_FIELD = 'CacheControlDefaultsVersion';

    /**
     * The configured defaults version, where 0 means no defaults are configured.
     *
     * @return int
     */
    public static function configuredVersion(): int
    {
        return (int)SiteConfig::config()->get('cache_control_defaults_version');
    }

    /**
     * Whether the project has configured a version above zero and a non-empty map of defaults.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::configuredVersion() > 0 && self::configuredMap() !== [];
    }

    /**
     * The configured map of SiteConfig field names to values, with every key checked.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When a key is not a field the project may set
     */
    public static function configuredDefaults(): array
    {
        $defaults = self::configuredMap();
        $allowedFields = self::allowedFields();
        $unknownKeys = array_diff(array_keys($defaults), $allowedFields);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown key "%s" in cache_control_defaults. Allowed keys: %s.',
                implode('", "', $unknownKeys),
                implode(', ', $allowedFields)
            ));
        }

        return $defaults;
    }

    /**
     * Whether a row's stored version is lower than the configured version.
     *
     * @param SiteConfig $row The row to check
     * @return bool
     */
    public static function isDue(SiteConfig $row): bool
    {
        return (int)$row->{self::VERSION_FIELD} < self::configuredVersion();
    }

    /**
     * Assign the configured fields and the configured version to a row without writing it, casting enum values to strings.
     *
     * @param SiteConfig $row The row to update in memory
     * @return void
     * @throws InvalidArgumentException When a configured key is not a field the project may set
     */
    public static function assign(SiteConfig $row): void
    {
        if (!self::isConfigured()) {
            return;
        }

        foreach (self::configuredDefaults() as $field => $value) {
            $row->{$field} = $row->dbObject($field) instanceof DBEnum ? (string)$value : $value;
        }

        $row->{self::VERSION_FIELD} = self::configuredVersion();
    }

    /**
     * Apply the configured defaults to every SiteConfig row whose stored version is lower, writing none unless every due row is valid.
     *
     * @return void
     * @throws InvalidArgumentException When a configured key is not a field the project may set
     * @throws ValidationException When a due row is invalid with the defaults assigned
     */
    public static function applyToAll(): void
    {
        if (!self::isConfigured()) {
            return;
        }

        // Check the map before any row is read, so a mistyped key fails even when no row is due.
        self::configuredDefaults();

        $version = self::configuredVersion();
        $dueRows = [];
        $failures = [];

        // Switch off the subsites module's filter, so the rows of every subsite are read.
        $rows = SiteConfig::get()->setDataQueryParam('Subsite.filter', false)->sort('ID');
        foreach ($rows as $row) {
            if (!self::isDue($row)) {
                continue;
            }

            self::assign($row);
            $result = $row->validate();
            if (!$result->isValid()) {
                $failures[] = self::describeFailure($row, $result);
            }
            $dueRows[] = $row;
        }

        if ($failures !== []) {
            throw new ValidationException(sprintf(
                'Cache control defaults version %d is invalid for %s. No SiteConfig rows were changed.',
                $version,
                implode('; ', $failures)
            ));
        }

        foreach ($dueRows as $row) {
            $row->write();
            DB::alteration_message(
                sprintf('Applied cache control defaults version %d to SiteConfig #%d', $version, $row->ID),
                'changed'
            );
        }
    }

    /**
     * Describe a row's validation failure as its ID followed by each field message.
     *
     * @param SiteConfig $row The row that failed validation
     * @param ValidationResult $result The failed validation result
     * @return string
     */
    private static function describeFailure(SiteConfig $row, ValidationResult $result): string
    {
        $messages = [];
        foreach ($result->getMessages() as $message) {
            $fieldPrefix = $message['fieldName'] !== '' ? $message['fieldName'] . ': ' : '';
            $messages[] = $fieldPrefix . $message['message'];
        }

        return sprintf('SiteConfig #%d (%s)', $row->ID, implode('; ', $messages));
    }

    /**
     * The configured map exactly as the project wrote it, with no key checks.
     *
     * @return array<string|int, mixed>
     */
    private static function configuredMap(): array
    {
        return (array)SiteConfig::config()->get('cache_control_defaults');
    }

    /**
     * The fields a project may set: the SiteConfig extension's own database fields, except the version.
     *
     * @return string[]
     */
    private static function allowedFields(): array
    {
        $extensionFields = array_keys(
            (array)Config::inst()->get(CacheControlSiteConfigExtension::class, 'db', Config::UNINHERITED)
        );

        return array_values(array_diff($extensionFields, [self::VERSION_FIELD]));
    }
}
