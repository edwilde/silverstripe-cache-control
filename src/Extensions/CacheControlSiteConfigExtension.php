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
 * @package Edwilde\CacheControls
 * @author Ed Wilde
 */

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use UncleCheese\DisplayLogic\Forms\Wrapper;

/**
 * Site-wide cache control extension
 *
 * Provides default cache control settings that apply to all pages unless
 * overridden at the page level.
 */
class CacheControlSiteConfigExtension extends DataExtension
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
        'EnableMustRevalidate' => 'Boolean',
    ];

    /**
     * Default values for cache control fields
     * Cache control is disabled by default to avoid unintended caching behavior
     * Must-revalidate is enabled by default as recommended for most scenarios
     *
     * @var array
     */
    private static $defaults = [
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'CacheDuration' => 'maxage',
        'MaxAge' => 120,
        'EnableMustRevalidate' => true,
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
        $cacheTypeField = OptionsetField::create('CacheType', 'Cache Type', [
            'public' => 'Public - Allow browsers and CDNs to cache (recommended for public pages)',
            'private' => 'Private - Only allow browser caching, not CDN/proxy caching (for user-specific content)',
        ])->setDescription('Choose who can cache your pages.');

        $cacheDurationField = OptionsetField::create('CacheDuration', 'Cache Duration', [
            'maxage' => 'Cache with Max Age - Allow caching for a specified time',
            'nostore' => 'No Store - Prevent all caching (for sensitive or frequently changing content)',
        ])->setDescription('Choose how long content can be cached.');

        $maxAgeField = NumericField::create('MaxAge', 'Max Age (seconds)')
            ->setDescription('Default is 120 seconds (2 minutes). Common values: 60 (1 min), 300 (5 mins), 3600 (1 hour), 86400 (1 day).')
            ->setAttribute('placeholder', '120');

        $mustRevalidateField = CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
            ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content (recommended).');

        // Apply display logic - fields show/hide based on conditions
        // Note: OptionsetFields must be wrapped for display logic to work properly
        $cacheTypeWrapper = Wrapper::create($cacheTypeField);
        $cacheTypeWrapper->displayIf('EnableCacheControl')->isChecked()->end();

        $cacheDurationWrapper = Wrapper::create($cacheDurationField);
        $cacheDurationWrapper->displayIf('EnableCacheControl')->isChecked()->end();

        // Only show max-age related fields when duration is set to 'maxage'
        $maxAgeField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();
        $mustRevalidateField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('EnableCacheControl')->isChecked();

        $fields->addFieldsToTab('Root.CacheControl', [
            HeaderField::create('CacheControlHeader', 'Cache-Control Settings', 2),
            LiteralField::create('CacheControlInfo',
                '<p class="message notice">Control how browsers and CDNs cache your website pages. ' .
                'Enable cache control to improve performance by allowing browsers to store copies of your pages.</p>'
            ),
            CheckboxField::create('EnableCacheControl', 'Enable Cache Control')
                ->setDescription('Turn on cache control headers for this site. When disabled, no cache headers will be added.'),
            $cacheTypeWrapper,
            $cacheDurationWrapper,
            $maxAgeField,
            $mustRevalidateField,
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
            $maxAge = (int)$this->owner->MaxAge ?: 120;
            $directives[] = 'max-age=' . $maxAge;
        }

        // Add must-revalidate if enabled
        if ($this->owner->EnableMustRevalidate) {
            $directives[] = 'must-revalidate';
        }

        return !empty($directives) ? implode(', ', $directives) : null;
    }
}
