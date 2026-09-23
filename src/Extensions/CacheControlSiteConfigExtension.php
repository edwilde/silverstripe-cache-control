<?php

/**
 * Cache Control Site Config Extension
 *
 * Extends SiteConfig to provide site-wide cache control header configuration.
 * These settings act as defaults for all pages unless overridden at the page level.
 *
 * Features:
 * - Enable/disable cache control for the entire site
 * - Choose cache type (public/private)
 * - Set cache duration (max-age or no-store)
 * - Configure max-age value in seconds
 * - Enable must-revalidate directive
 * - Conditional field visibility using display logic
 *
 * @package Edwilde\CacheControl
 * @author Ed Wilde
 */

namespace Edwilde\CacheControl\Extensions;

use Edwilde\CacheControl\StaleDirectives;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\ToggleCompositeField;
use UncleCheese\DisplayLogic\Forms\Wrapper;

/**
 * Site-wide cache control extension
 *
 * Provides default cache control settings that apply to all pages unless
 * overridden at the page level.
 */
class CacheControlSiteConfigExtension extends Extension
{
    /**
     * Database fields for site-wide cache control
     *
     * @var array
     */
    private static $db = [
        'EnableCacheControl' => 'Boolean',
        'CacheType' => 'Enum("public,private","public")',
        'CacheDuration' => 'Enum("maxage,nostore","maxage")',
        'MaxAge' => 'Int',
        'MaxAgePreset' => 'Enum("120,300,600,3600,86400,custom","120")',
        'EnableMustRevalidate' => 'Boolean',
        'StaleWhileRevalidatePreset' => StaleDirectives::PRESET_ENUM,
        'StaleWhileRevalidate' => 'Int',
        'StaleIfErrorPreset' => StaleDirectives::PRESET_ENUM,
        'StaleIfError' => 'Int',
        'VaryAcceptEncoding' => 'Boolean',
        'VaryXForwardedProtocol' => 'Boolean',
        'VaryCookie' => 'Boolean',
        'VaryAuthorization' => 'Boolean',
        'EnableDraftCacheReduction' => 'Boolean',
    ];

    /**
     * Default values for cache control fields
     * Cache control is disabled by default to avoid unintended caching behavior
     * Must-revalidate is enabled by default as recommended for most scenarios
     * Accept-Encoding is enabled by default as it's standard practice
     *
     * @var array
     */
    private static $defaults = [
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'CacheDuration' => 'maxage',
        'MaxAge' => 120,
        'MaxAgePreset' => '120',
        'EnableMustRevalidate' => true,
        'StaleWhileRevalidatePreset' => StaleDirectives::PRESET_OFF,
        'StaleWhileRevalidate' => 0,
        'StaleIfErrorPreset' => StaleDirectives::PRESET_OFF,
        'StaleIfError' => 0,
        'VaryAcceptEncoding' => true,
        'VaryXForwardedProtocol' => false,
        'VaryCookie' => false,
        'VaryAuthorization' => false,
        'EnableDraftCacheReduction' => true,
    ];

    /**
     * Add cache control fields to the Site Config CMS
     *
     * Creates a new "Cache Control" tab with fields for site-wide cache settings.
     * Uses display logic to show/hide fields based on selections.
     *
     * @param FieldList $fields The current CMS fields
     * @return void
     */
    public function updateCMSFields(FieldList $fields)
    {
        // Remove scaffolded fields — we replace them with custom versions below.
        // CMS 6 enforces unique field names in FieldList, so these must be removed first.
        $fields->removeByName([
            'EnableCacheControl',
            'CacheType',
            'CacheDuration',
            'MaxAge',
            'MaxAgePreset',
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
        ]);

        $cacheTypeField = OptionsetField::create('CacheType', 'Cache Type', [
            'public' => 'Public - Allow browsers and CDNs to cache (recommended for public pages)',
            'private' => 'Private - Only allow browser caching, not CDN/proxy caching (for user-specific content)',
        ])->setDescription('Choose who can cache your pages.');

        $cacheDurationField = OptionsetField::create('CacheDuration', 'Cache Duration', [
            'maxage' => 'Cache with Max Age - Allow caching for a specified time',
            'nostore' => 'No Store - Prevent all caching (for sensitive or frequently changing content)',
        ])->setDescription('Choose how long content can be cached.');

        $maxAgePresetField = DropdownField::create('MaxAgePreset', 'Max Age Duration', [
            '120' => '2 minutes (120 seconds)',
            '300' => '5 minutes (300 seconds)',
            '600' => '10 minutes (600 seconds)',
            '3600' => '1 hour (3600 seconds)',
            '86400' => '1 day (86400 seconds)',
            'custom' => 'Custom (specify in seconds)',
        ])->setDescription('Choose a common cache duration or select custom to specify your own.');

        $maxAgeField = NumericField::create('MaxAge', 'Custom Max Age (seconds)')
            ->setDescription('Enter a custom cache duration in seconds.')
            ->setAttribute('placeholder', '120');

        $staleInfoField = StaleDirectives::infoField('StaleDirectivesInfo');

        $staleWhileRevalidatePresetField = DropdownField::create(
            'StaleWhileRevalidatePreset',
            'Refresh Grace Period',
            StaleDirectives::presetOptions()
        )->setDescription('How long caches may serve the expired copy while fetching a fresh one in the background.');

        $staleWhileRevalidateField = NumericField::create('StaleWhileRevalidate', 'Custom Refresh Grace Period (seconds)')
            ->setDescription('Enter a custom refresh grace period in seconds, up to one year (31536000).')
            ->setAttribute('placeholder', '86400');

        $staleIfErrorPresetField = DropdownField::create(
            'StaleIfErrorPreset',
            'Error Grace Period',
            StaleDirectives::presetOptions()
        )->setDescription('How long caches may keep serving the stored copy while the server returns errors.');

        $staleIfErrorField = NumericField::create('StaleIfError', 'Custom Error Grace Period (seconds)')
            ->setDescription('Enter a custom error grace period in seconds, up to one year (31536000).')
            ->setAttribute('placeholder', '604800');

        $mustRevalidateField = CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
            ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content. '
                . 'Not available while a grace period is set, which asks caches to do the opposite.');

        // Apply display logic - fields show/hide based on conditions
        // Note: OptionsetFields must be wrapped for display logic to work properly
        $cacheTypeWrapper = Wrapper::create($cacheTypeField);
        $cacheTypeWrapper->displayIf('EnableCacheControl')->isChecked()->end();

        $cacheDurationWrapper = Wrapper::create($cacheDurationField);
        $cacheDurationWrapper->displayIf('EnableCacheControl')->isChecked()->end();

        // Only show max-age related fields when duration is set to 'maxage'
        $maxAgePresetField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        // Only show custom max-age input when preset is set to 'custom'
        $maxAgeField->displayIf('MaxAgePreset')->isEqualTo('custom')
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        $staleWhileRevalidatePresetField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        $staleWhileRevalidateField->displayIf('StaleWhileRevalidatePreset')->isEqualTo(StaleDirectives::PRESET_CUSTOM)
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        $staleIfErrorPresetField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        $staleIfErrorField->displayIf('StaleIfErrorPreset')->isEqualTo(StaleDirectives::PRESET_CUSTOM)
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        // Hidden while either grace period is set, since the two cancel each other out.
        $mustRevalidateField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked()
            ->andIf('StaleWhileRevalidatePreset')->isEqualTo(StaleDirectives::PRESET_OFF)
            ->andIf('StaleIfErrorPreset')->isEqualTo(StaleDirectives::PRESET_OFF);

        $draftReductionField = CheckboxField::create(
            'EnableDraftCacheReduction',
            'Reduce cache time for pages with unpublished changes'
        )->setDescription(
            'When a page has been saved but not published, its cache time is temporarily reduced '
            . 'so visitors see the new content sooner after publishing.'
        );

        $draftReductionField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        // Main cache control settings in a collapsible section
        $cacheControlSection = ToggleCompositeField::create('CacheControlSettings', 'Cache-Control Header (Advanced)',
            [
                $cacheTypeWrapper,
                $cacheDurationWrapper,
                $maxAgePresetField,
                $maxAgeField,
                $staleInfoField,
                StaleDirectives::privateNoticeField('StaleDirectivesPrivateNotice'),
                $staleWhileRevalidatePresetField,
                $staleWhileRevalidateField,
                $staleIfErrorPresetField,
                $staleIfErrorField,
                $mustRevalidateField,
                $draftReductionField,
            ]
        )->setStartClosed(true);

        // Wrap cache control section to control visibility with display logic
        $cacheControlWrapper = Wrapper::create($cacheControlSection);
        $cacheControlWrapper->displayIf('EnableCacheControl')->isChecked()->end();

        // Vary header fields in a collapsible section
        $varySection = ToggleCompositeField::create('VarySettings', 'Vary Header (Advanced)',
            [
                LiteralField::create('VaryInfo',
                    '<p class="message notice">The Vary header tells caches which request headers affect the response. ' .
                    'Select which headers should cause separate cache entries to be stored.</p>'
                ),
                CheckboxField::create('VaryAcceptEncoding', 'Accept-Encoding')
                    ->setDescription('Store separate cache entries for different compression methods (gzip, br, etc). Recommended for most sites.'),
                CheckboxField::create('VaryXForwardedProtocol', 'X-Forwarded-Protocol')
                    ->setDescription('Store separate cache entries for HTTP vs HTTPS requests.'),
                CheckboxField::create('VaryCookie', 'Cookie')
                    ->setDescription('Store separate cache entries when cookies differ. Use for personalized content.'),
                CheckboxField::create('VaryAuthorization', 'Authorization')
                    ->setDescription('Store separate cache entries based on authentication. Use for protected content.'),
            ]
        )->setStartClosed(true);

        // Wrap Vary section to control visibility with display logic
        $varyWrapper = Wrapper::create($varySection);
        $varyWrapper->displayIf('EnableCacheControl')->isChecked()->end();

        $fields->addFieldsToTab('Root.CacheControl', [
            HeaderField::create('CacheControlHeader', 'Cache-Control Settings', 2),
            LiteralField::create('CacheControlInfo',
                '<p class="message notice">Control how browsers and CDNs cache your website pages. ' .
                'Enable cache control to improve performance by allowing browsers to store copies of your pages.</p>'
            ),
            CheckboxField::create('EnableCacheControl', 'Enable Cache Control')
                ->setDescription('Turn on cache control headers for this site. When disabled, no cache headers will be added.'),
            $cacheControlWrapper,
            $varyWrapper,
        ]);
    }

    /**
     * Get the site-wide cache control header
     *
     * Builds the cache control header value from the configured settings.
     * This is used as the default for all pages that don't override it.
     *
     * @return string|null The cache control header value, or null if disabled
     */
    public function getCacheControlHeader()
    {
        if (!$this->owner->EnableCacheControl) {
            return null;
        }

        $directives = [];

        // no-store overrides everything else - just return no-store alone
        if ($this->owner->CacheDuration === 'nostore') {
            return 'no-store';
        }

        // Add cache type (public/private)
        if ($this->owner->CacheType) {
            $directives[] = $this->owner->CacheType;
        }

        // Add max-age if using maxage duration
        if ($this->owner->CacheDuration === 'maxage') {
            // Use preset value unless 'custom' is selected, then use MaxAge field
            $maxAge = 120; // fallback default
            if ($this->owner->MaxAgePreset === 'custom') {
                $maxAge = (int)$this->owner->MaxAge > 0 ? (int)$this->owner->MaxAge : 120;
            } else {
                $maxAge = (int)$this->owner->MaxAgePreset ?: 120;
            }
            $directives[] = 'max-age=' . $maxAge;

            // Grace periods follow max-age, and replace must-revalidate when set
            foreach (StaleDirectives::forSource($this->owner) as $directive => $seconds) {
                $directives[] = $directive . '=' . $seconds;
            }
        }

        // Add must-revalidate if enabled and no grace period cancels it
        if ($this->owner->EnableMustRevalidate && !StaleDirectives::hasGracePeriod($this->owner)) {
            $directives[] = 'must-revalidate';
        }

        return !empty($directives) ? implode(', ', $directives) : null;
    }

    /**
     * Get the Vary header value
     *
     * Builds the Vary header from enabled options. This header tells caches
     * which request headers affect the response, allowing separate cache
     * entries for different variations.
     *
     * @return string|null The Vary header value, or null if no options enabled
     */
    public function getVaryHeader()
    {
        if (!$this->owner->EnableCacheControl) {
            return null;
        }

        $varyHeaders = [];

        if ($this->owner->VaryAcceptEncoding) {
            $varyHeaders[] = 'Accept-Encoding';
        }

        if ($this->owner->VaryXForwardedProtocol) {
            $varyHeaders[] = 'X-Forwarded-Protocol';
        }

        if ($this->owner->VaryCookie) {
            $varyHeaders[] = 'Cookie';
        }

        if ($this->owner->VaryAuthorization) {
            $varyHeaders[] = 'Authorization';
        }

        return !empty($varyHeaders) ? implode(', ', $varyHeaders) : null;
    }

    /**
     * Validate cache control settings before writing
     *
     * @param ValidationResult $result
     * @return void
     */
    /**
     * Validate cache control settings before writing (CMS 5 / early CMS 6)
     *
     * @param ValidationResult $result
     * @return void
     */
    public function validate($result): void
    {
        $this->doValidateMaxAge($result);
    }

    /**
     * Validate cache control settings before writing (CMS 6)
     *
     * @param ValidationResult $result
     * @return void
     */
    public function updateValidate(ValidationResult $result): void
    {
        $this->doValidateMaxAge($result);
    }

    private function doValidateMaxAge($result): void
    {
        if (!$this->owner->EnableCacheControl || $this->owner->CacheDuration !== 'maxage') {
            return;
        }

        if ($this->owner->MaxAgePreset === 'custom' && (int)$this->owner->MaxAge < 1) {
            $result->addFieldError('MaxAge', 'Custom max age must be at least 1 second.');
        }

        StaleDirectives::validate($this->owner, $result);
    }
}
