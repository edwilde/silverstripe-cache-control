<?php

declare(strict_types=1);

/**
 * Shared Max Age Helper
 *
 * Shared resolution of the `s-maxage` CDN cache duration directive for SiteConfig, Page and the
 * content controller.
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
 * Resolves the configured CDN cache duration into a directive value.
 *
 * Stored as a preset/custom pair, matching the MaxAgePreset/MaxAge pattern: the preset holds
 * either a seconds value, "0" for off, or "custom" to read the paired Int field.
 */
final class SharedMaxAge
{
    /**
     * Preset value meaning the directive is not emitted.
     */
    public const PRESET_OFF = StaleDirectives::PRESET_OFF;

    /**
     * Preset value meaning the paired Int field supplies the seconds.
     */
    public const PRESET_CUSTOM = StaleDirectives::PRESET_CUSTOM;

    /**
     * Enum spec for the CDN cache duration preset, defaulting to off.
     */
    public const PRESET_ENUM = 'Enum("0,300,3600,86400,604800,2592000,custom","0")';

    /**
     * Dropdown options for the CDN cache duration preset, from 5 minutes to 30 days.
     *
     * @return array<string, string>
     */
    public static function presetOptions(): array
    {
        return [
            self::PRESET_OFF => 'Same as Max Age Duration (default)',
            '300' => '5 minutes (300 seconds)',
            '3600' => '1 hour (3600 seconds)',
            '86400' => '1 day (86400 seconds)',
            '604800' => '7 days (604800 seconds)',
            '2592000' => '30 days (2592000 seconds)',
            self::PRESET_CUSTOM => 'Custom (specify in seconds)',
        ];
    }

    /**
     * The CDN cache duration that should appear in a Cache-Control header.
     *
     * @param SiteConfig|SiteTree $source The object carrying the preset/custom fields
     * @return int Seconds, or 0 when the CDN cache duration should not be emitted, including for a
     *             private cache type
     */
    public static function forSource(SiteConfig|SiteTree $source): int
    {
        if ($source->CacheType !== 'public') {
            return 0;
        }

        return StaleDirectives::resolve($source->SharedMaxAgePreset, $source->SharedMaxAge);
    }

    /**
     * Add a field error when the custom CDN cache duration is outside 1 second to MAX_SECONDS.
     *
     * Skipped for a private cache type, where the CMS hides the CDN cache duration fields.
     *
     * @param SiteConfig|SiteTree $source The object carrying the preset/custom fields
     * @param ValidationResult $result The result to add field errors to
     * @return void
     */
    public static function validate(SiteConfig|SiteTree $source, ValidationResult $result): void
    {
        if ($source->CacheType !== 'public' || (string)$source->SharedMaxAgePreset !== self::PRESET_CUSTOM) {
            return;
        }

        $seconds = (int)$source->SharedMaxAge;

        if ($seconds < 1) {
            $result->addFieldError('SharedMaxAge', 'Custom CDN cache duration must be at least 1 second.');
        } elseif ($seconds > StaleDirectives::MAX_SECONDS) {
            $result->addFieldError(
                'SharedMaxAge',
                'Custom CDN cache duration must be no more than ' . StaleDirectives::MAX_SECONDS
                . ' seconds (one year).'
            );
        }
    }

    /**
     * The explainer shown to editors above the CDN cache duration field.
     *
     * @param string $name The form field name, unique within the CMS form
     * @return Wrapper
     */
    public static function infoField(string $name): Wrapper
    {
        $notice = Wrapper::create(LiteralField::create($name,
            '<p class="message notice">A CDN keeps copies of your pages close to visitors. A longer CDN time '
            . 'means fewer requests reach your server, but changes wait for the CDN\'s copy to expire. Only set '
            . 'this if your CDN is cleared when you publish.</p>'
        ));
        $notice->displayIf('CacheType')->isEqualTo('public')
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->end();

        return $notice;
    }
}
