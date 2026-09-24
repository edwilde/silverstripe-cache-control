<?php

declare(strict_types=1);

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

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\LiteralField;
use SilverStripe\SiteConfig\SiteConfig;
use UncleCheese\DisplayLogic\Forms\Wrapper;

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
     * Enum spec for the refresh grace-period preset (stale-while-revalidate), defaulting to off.
     */
    public const REFRESH_PRESET_ENUM = 'Enum("0,300,3600,21600,86400,604800,custom","0")';

    /**
     * Enum spec for the error grace-period preset (stale-if-error), defaulting to off.
     */
    public const ERROR_PRESET_ENUM = 'Enum("0,3600,86400,604800,2592000,custom","0")';

    /**
     * The largest grace period a custom value may set, in seconds (one year).
     */
    public const MAX_SECONDS = 31536000;

    /**
     * Directive name to the preset/custom field pair that configures it.
     *
     * @var array<string, array{preset: string, custom: string, label: string}>
     */
    private const FIELDS = [
        'stale-while-revalidate' => [
            'preset' => 'StaleWhileRevalidatePreset',
            'custom' => 'StaleWhileRevalidate',
            'label' => 'refresh grace period',
        ],
        'stale-if-error' => [
            'preset' => 'StaleIfErrorPreset',
            'custom' => 'StaleIfError',
            'label' => 'error grace period',
        ],
    ];

    /**
     * Dropdown options for the refresh grace-period preset, in minutes to days.
     *
     * @return array<string, string>
     */
    public static function refreshPresetOptions(): array
    {
        return [
            self::PRESET_OFF => 'Off - no grace period',
            '300' => '5 minutes (300 seconds)',
            '3600' => '1 hour (3600 seconds)',
            '21600' => '6 hours (21600 seconds)',
            '86400' => '1 day (86400 seconds)',
            '604800' => '7 days (604800 seconds)',
            self::PRESET_CUSTOM => 'Custom (specify in seconds)',
        ];
    }

    /**
     * Dropdown options for the error grace-period preset, in hours to weeks.
     *
     * @return array<string, string>
     */
    public static function errorPresetOptions(): array
    {
        return [
            self::PRESET_OFF => 'Off - no grace period',
            '3600' => '1 hour (3600 seconds)',
            '86400' => '1 day (86400 seconds)',
            '604800' => '7 days (604800 seconds)',
            '2592000' => '30 days (2592000 seconds)',
            self::PRESET_CUSTOM => 'Custom (specify in seconds)',
        ];
    }

    /**
     * Resolve a preset/custom pair to a number of seconds.
     *
     * A custom value below 1 resolves to 0, turning the grace period off.
     *
     * @param int|string|null $preset The preset value: seconds, "0" or "custom"
     * @param int|string|null $customValue The paired Int field, read only when the preset is "custom"
     * @return int Seconds, or 0 when the directive should not be emitted
     */
    public static function resolve(int|string|null $preset, int|string|null $customValue): int
    {
        if ((string)$preset === self::PRESET_CUSTOM) {
            return (int)$customValue > 0 ? (int)$customValue : 0;
        }

        return max(0, (int)$preset);
    }

    /**
     * Resolve both grace periods from a SiteConfig or Page.
     *
     * @param SiteConfig|SiteTree $source The object carrying the preset/custom fields
     * @return array<string, int> Directive name to seconds, including any resolving to 0
     */
    public static function resolveAll(SiteConfig|SiteTree $source): array
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
     * @param SiteConfig|SiteTree $source The object carrying the preset/custom fields
     * @return array<string, int> Directive name to seconds, omitting any resolving to 0
     */
    public static function forSource(SiteConfig|SiteTree $source): array
    {
        return array_filter(self::resolveAll($source));
    }

    /**
     * Whether either grace period is set on the given source.
     *
     * must-revalidate is omitted from the header whenever this is true.
     *
     * @param SiteConfig|SiteTree $source The object carrying the preset/custom fields
     * @return bool
     */
    public static function hasGracePeriod(SiteConfig|SiteTree $source): bool
    {
        return self::forSource($source) !== [];
    }

    /**
     * Add a field error for each custom grace period outside 1 second to MAX_SECONDS.
     *
     * @param SiteConfig|SiteTree $source The object carrying the preset/custom fields
     * @param ValidationResult $result The result to add field errors to
     * @return void
     */
    public static function validate(SiteConfig|SiteTree $source, ValidationResult $result): void
    {
        foreach (self::FIELDS as $fields) {
            if ((string)$source->{$fields['preset']} !== self::PRESET_CUSTOM) {
                continue;
            }

            $seconds = (int)$source->{$fields['custom']};

            if ($seconds < 1) {
                $result->addFieldError($fields['custom'], "Custom {$fields['label']} must be at least 1 second.");
            } elseif ($seconds > self::MAX_SECONDS) {
                $result->addFieldError(
                    $fields['custom'],
                    "Custom {$fields['label']} must be no more than " . self::MAX_SECONDS . ' seconds (one year).'
                );
            }
        }
    }

    /**
     * The explainer shown to editors above the grace-period fields.
     *
     * @param string $name The form field name, unique within the CMS form
     * @return LiteralField
     */
    public static function infoField(string $name): LiteralField
    {
        return LiteralField::create($name,
            '<p class="message notice">Grace periods let a CDN keep serving its stored copy after the max age runs out. '
            . 'The <strong>refresh grace period</strong> serves that copy instantly while fetching a fresh one in the '
            . 'background, so no visitor waits for the page to be rebuilt. The <strong>error grace period</strong> keeps '
            . 'the copy in service while the server is returning errors. Both pair with a short max age.</p>'
        );
    }

    /**
     * A notice that grace periods only reach the browser, shown while the cache type is private.
     *
     * @param string $name The form field name, unique within the CMS form
     * @return Wrapper
     */
    public static function privateNoticeField(string $name): Wrapper
    {
        $notice = Wrapper::create(LiteralField::create($name,
            '<p class="message notice">With a <strong>private</strong> cache type, CDNs ignore both grace periods: '
            . 'only the visitor\'s browser applies them, and most browsers ignore the error grace period.</p>'
        ));
        $notice->displayIf('CacheType')->isEqualTo('private')
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->end();

        return $notice;
    }
}
