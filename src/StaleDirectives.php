<?php

/**
 * Stale Directive Helper
 *
 * Shared resolution of the RFC 5861 grace-period directives (`stale-while-revalidate` and
 * `stale-if-error`) for SiteConfig, Page and the content controller.
 *
 * @package Edwilde\CacheControl
 * @author Ed Wilde
 */

namespace Edwilde\CacheControl;

/**
 * Resolves the configured grace periods into directive values.
 *
 * Each directive is stored as a preset/custom pair, matching the MaxAgePreset/MaxAge pattern:
 * the preset holds either a seconds value, "0" for off, or "custom" to read the paired Int field.
 */
final class StaleDirectives
{
    /**
     * Preset value meaning the directive is not emitted.
     */
    public const PRESET_OFF = '0';

    /**
     * Preset value meaning the paired Int field supplies the seconds.
     */
    public const PRESET_CUSTOM = 'custom';

    /**
     * Enum spec for both preset fields, defaulting to off.
     */
    public const PRESET_ENUM = 'Enum("0,3600,86400,604800,2592000,7776000,custom","0")';

    /**
     * Directive name to the preset/custom field pair that configures it.
     *
     * @var array<string, array{preset: string, custom: string}>
     */
    private const FIELDS = [
        'stale-while-revalidate' => [
            'preset' => 'StaleWhileRevalidatePreset',
            'custom' => 'StaleWhileRevalidate',
        ],
        'stale-if-error' => [
            'preset' => 'StaleIfErrorPreset',
            'custom' => 'StaleIfError',
        ],
    ];

    /**
     * Dropdown options for a grace-period preset field.
     *
     * @return array<string, string>
     */
    public static function presetOptions(): array
    {
        return [
            self::PRESET_OFF => 'Off - no grace period',
            '3600' => '1 hour (3600 seconds)',
            '86400' => '1 day (86400 seconds)',
            '604800' => '7 days (604800 seconds)',
            '2592000' => '30 days (2592000 seconds)',
            '7776000' => '90 days (7776000 seconds)',
            self::PRESET_CUSTOM => 'Custom (specify in seconds)',
        ];
    }

    /**
     * Resolve a preset/custom pair to a number of seconds.
     *
     * A custom value below 1 resolves to 0, turning the grace period off.
     *
     * @param string|null $preset The preset value: seconds, "0" or "custom"
     * @param mixed $customValue The paired Int field, read only when the preset is "custom"
     * @return int Seconds, or 0 when the directive should not be emitted
     */
    public static function resolve($preset, $customValue): int
    {
        if ((string)$preset === self::PRESET_CUSTOM) {
            return (int)$customValue > 0 ? (int)$customValue : 0;
        }

        return max(0, (int)$preset);
    }

    /**
     * Resolve both grace periods from a SiteConfig or Page.
     *
     * @param object $source The object carrying the preset/custom fields
     * @return array<string, int> Directive name to seconds, including any resolving to 0
     */
    public static function resolveAll($source): array
    {
        $resolved = [];

        foreach (self::FIELDS as $directive => $fields) {
            $resolved[$directive] = self::resolve($source->{$fields['preset']}, $source->{$fields['custom']});
        }

        return $resolved;
    }

    /**
     * The grace periods that should appear in a Cache-Control header.
     *
     * @param object $source The object carrying the preset/custom fields
     * @return array<string, int> Directive name to seconds, omitting any resolving to 0
     */
    public static function forSource($source): array
    {
        return array_filter(self::resolveAll($source));
    }

    /**
     * Whether either grace period is set on the given source.
     *
     * must-revalidate is omitted from the header whenever this is true.
     *
     * @param object $source The object carrying the preset/custom fields
     * @return bool
     */
    public static function hasGracePeriod($source): bool
    {
        return self::forSource($source) !== [];
    }
}
