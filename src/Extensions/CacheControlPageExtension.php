<?php

/**
 * Cache Control Page Extension
 *
 * Extends Page objects to provide page-level cache control header configuration.
 * Allows editors to override site-wide cache settings on a per-page basis.
 *
 * Features:
 * - Optional override of site-wide cache settings
 * - Same controls as site config (cache type, duration, max-age, must-revalidate)
 * - Automatic pre-filling with site defaults when override is enabled
 * - Clear visual indication of current cache control header
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
use SilverStripe\SiteConfig\SiteConfig;
use UncleCheese\DisplayLogic\Forms\Wrapper;

/**
 * Page-level cache control extension
 *
 * Provides granular cache control at the page level, with the ability to override
 * site-wide settings. When override is disabled, pages inherit site config settings.
 */
class CacheControlPageExtension extends DataExtension
{
    /**
     * Database fields for page-level cache control
     *
     * @var array
     */
    private static $db = [
        'OverrideCacheControl' => 'Boolean',
        'EnableCacheControl' => 'Boolean',
        'CacheType' => 'Enum("public,private","public")',
        'CacheDuration' => 'Enum("maxage,nostore","maxage")',
        'MaxAge' => 'Int',
        'EnableMustRevalidate' => 'Boolean',
    ];

    /**
     * Default values for cache control fields
     * Cache control is disabled by default to avoid unintended caching behavior
     *
     * @var array
     */
    private static $defaults = [
        'OverrideCacheControl' => false,
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'CacheDuration' => 'maxage',
        'MaxAge' => 120,
        'EnableMustRevalidate' => false,
    ];

    /**
     * Add cache control fields to the CMS
     *
     * Creates a new "Cache Control" tab with fields for:
     * - Current cache control header display
     * - Override checkbox to enable page-specific settings
     * - All cache control options (conditionally visible)
     *
     * Uses display logic to show/hide fields based on selections.
     *
     * @param FieldList $fields The current CMS fields
     * @return void
     */
    public function updateCMSFields(FieldList $fields)
    {
        // Display the current effective cache control header
        $effectiveHeader = $this->owner->getEffectiveCacheControlDescription();

        // Get site config for later use in pre-filling values
        $siteConfig = SiteConfig::current_site_config();

        $headerField = HeaderField::create('CacheControlHeader', 'Page Cache-Control Settings', 2);

        $currentCacheControlField = LiteralField::create('CurrentCacheControl',
            '<div class="message notice">' .
            '<strong>Current Cache-Control Header:</strong><br>' .
            $effectiveHeader .
            '</div>'
        );

        $overrideField = CheckboxField::create('OverrideCacheControl', 'Override Site Cache Settings')
            ->setDescription('Enable this to set custom cache control for this specific page, overriding site-wide settings.');

        $pageHeaderField = HeaderField::create('PageCacheControlHeader', 'Page-Specific Cache Settings', 3);
        $pageInfoField = LiteralField::create('PageCacheControlInfo',
            '<p class="message info">These settings will only apply to this page and override the site-wide cache settings. Values are pre-filled with current site defaults.</p>'
        );
        $enableCacheField = CheckboxField::create('EnableCacheControl', 'Enable Cache Control for this Page')
            ->setDescription('Turn on cache control headers for this page.');
        $cacheTypeField = OptionsetField::create('CacheType', 'Cache Type', [
            'public' => 'Public - Allow browsers and CDNs to cache (recommended for public pages)',
            'private' => 'Private - Only allow browser caching, not CDN/proxy caching (for user-specific content)',
        ])->setDescription('Choose who can cache this page.');
        $cacheDurationField = OptionsetField::create('CacheDuration', 'Cache Duration', [
            'maxage' => 'Cache with Max Age - Allow caching for a specified time',
            'nostore' => 'No Store - Prevent all caching (for sensitive or frequently changing content)',
        ])->setDescription('Choose how long content can be cached.');
        $maxAgeField = NumericField::create('MaxAge', 'Max Age (seconds)')
            ->setDescription('Default is 120 seconds (2 minutes). Common values: 60 (1 min), 300 (5 mins), 3600 (1 hour), 86400 (1 day).')
            ->setAttribute('placeholder', '120');
        $mustRevalidateField = CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
            ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content.');

        // Apply Display Logic - fields show/hide based on conditions
        // First level: only show when override is enabled
        $pageHeaderField->displayIf('OverrideCacheControl')->isChecked();
        $pageInfoField->displayIf('OverrideCacheControl')->isChecked();
        $enableCacheField->displayIf('OverrideCacheControl')->isChecked();

        // Second level: show when override AND cache control are enabled
        // Note: OptionsetFields must be wrapped for display logic to work properly
        $cacheTypeWrapper = Wrapper::create($cacheTypeField);
        $cacheTypeWrapper->displayIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked()->end();

        $cacheDurationWrapper = Wrapper::create($cacheDurationField);
        $cacheDurationWrapper->displayIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked()->end();

        // Third level: only show max-age related fields when duration is set to 'maxage'
        $maxAgeField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $mustRevalidateField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $fields->addFieldsToTab('Root.CacheControl', [
            $headerField,
            $currentCacheControlField,
            $overrideField,
            $pageHeaderField,
            $pageInfoField,
            $enableCacheField,
            $cacheTypeWrapper,
            $cacheDurationWrapper,
            $maxAgeField,
            $mustRevalidateField,
        ]);
    }

    /**
     * Pre-fill page settings with site config values when override is first enabled
     *
     * This provides a better user experience by starting with the current site defaults
     * rather than empty/default values. Only triggers when OverrideCacheControl changes
     * from false to true, and only for fields that have not been explicitly set.
     *
     * Does NOT pre-fill on subsequent saves to preserve user's explicit choices,
     * including intentionally disabling cache control for a specific page.
     *
     * @return void
     */
    public function onBeforeWrite()
    {
        // Pre-fill with site settings when override is first enabled
        // Only do this if OverrideCacheControl just changed from false to true
        if ($this->owner->isChanged('OverrideCacheControl') && $this->owner->OverrideCacheControl) {
            $changedFields = $this->owner->getChangedFields();
            $wasDisabled = isset($changedFields['OverrideCacheControl']) && 
                          !$changedFields['OverrideCacheControl']['before'];

            // Only pre-fill when first enabling override, not on subsequent edits
            if ($wasDisabled) {
                $siteConfig = SiteConfig::current_site_config();
                $this->owner->EnableCacheControl = $siteConfig->EnableCacheControl;
                $this->owner->CacheType = $siteConfig->CacheType ?: 'public';
                $this->owner->CacheDuration = $siteConfig->CacheDuration ?: 'maxage';
                $this->owner->MaxAge = $siteConfig->MaxAge ?: 120;
                $this->owner->EnableMustRevalidate = $siteConfig->EnableMustRevalidate;
            }
        }
    }

    /**
     * Get the cache control header for this page
     *
     * Returns either page-specific cache control or falls back to site config.
     * This is the main method called by the middleware to determine what header to set.
     *
     * When override is enabled, respects the page-specific EnableCacheControl setting,
     * which allows editors to explicitly disable caching on specific pages.
     * When override is disabled, falls back to site-wide settings.
     *
     * @return string|null The cache control header value, or null if none set
     */
    public function getCacheControlHeader()
    {
        // If override is enabled, use page-specific settings
        if ($this->owner->OverrideCacheControl) {
            // Only return page header if cache control is enabled for this page
            if ($this->owner->EnableCacheControl) {
                return $this->getPageCacheControlHeader();
            }
            // If override is enabled but cache control is disabled, return null (no caching)
            return null;
        }

        // Fall back to site config settings
        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig->hasExtension(CacheControlSiteConfigExtension::class)) {
            return $siteConfig->getCacheControlHeader();
        }

        return null;
    }

    /**
     * Build the cache control header from page-specific settings
     *
     * Constructs the header string based on selected options:
     * - If no-store: only returns "no-store"
     * - Otherwise: builds from cache type, max-age, and must-revalidate
     *
     * @return string|null The constructed cache control header value
     */
    private function getPageCacheControlHeader()
    {
        // Defensive check - shouldn't reach here if cache control is disabled
        if (!$this->owner->EnableCacheControl) {
            return null;
        }

        $directives = [];

        // no-store overrides everything else
        if ($this->owner->CacheDuration === 'nostore') {
            $directives[] = 'no-store';
            return implode(', ', $directives);
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

    /**
     * Get a human-readable description of the effective cache control header
     *
     * Shows what header is currently active and whether it comes from page-specific
     * settings or site-wide settings. Used in the CMS to provide clear feedback.
     *
     * @return string HTML-formatted description of the current cache control
     */
    public function getEffectiveCacheControlDescription()
    {
        $header = $this->owner->getCacheControlHeader();

        if (!$header) {
            $reason = $this->owner->OverrideCacheControl && !$this->owner->EnableCacheControl
                ? 'Cache control is disabled for this specific page.'
                : 'No cache control is currently set for this page.';
            
            return $reason . ' Browsers will use their default caching behavior.';
        }

        $source = $this->owner->OverrideCacheControl
            ? 'This is a <strong>page-specific setting</strong>.'
            : 'This is <strong>inherited from site-wide settings</strong>.';

        return sprintf(
            '<code>%s</code><br><small>%s</small>',
            htmlspecialchars($header),
            $source
        );
    }
}
