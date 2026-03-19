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
 * - Form fields pre-populated from site config so editors see what they will inherit
 * - Clear visual indication of current cache control header
 * - Conditional field visibility using display logic
 *
 * @package Edwilde\CacheControl
 * @author Ed Wilde
 */

namespace Edwilde\CacheControl\Extensions;

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
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\SiteConfig\SiteConfig;
use UncleCheese\DisplayLogic\Forms\Wrapper;

/**
 * Page-level cache control extension
 *
 * Provides granular cache control at the page level, with the ability to override
 * site-wide settings. When override is disabled, pages inherit site config settings.
 */
class CacheControlPageExtension extends Extension
{
    /**
     * Whether to enable cache inheritance from parent pages.
     *
     * When enabled, pages without their own cache override can inherit cache settings
     * from an ancestor page that has ApplyCacheToChildren enabled. This adds O(d) database
     * queries per uncached request (where d is tree depth, typically 3-5) to walk up the
     * page hierarchy.
     *
     * Disabled by default for performance. Enable via YAML config:
     *
     *     SilverStripe\CMS\Model\SiteTree:
     *       enable_cache_inheritance: true
     *
     * @config
     * @var bool
     */
    private static bool $enable_cache_inheritance = false;

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
        'MaxAgePreset' => 'Enum("120,300,600,3600,86400,custom","120")',
        'EnableMustRevalidate' => 'Boolean',
        'ApplyCacheToChildren' => 'Boolean',
    ];

    /**
     * Default values for cache control fields
     * Cache control is disabled by default to avoid unintended caching behavior
     * Must-revalidate is enabled by default as recommended for most scenarios
     *
     * @var array
     */
    private static $defaults = [
        'OverrideCacheControl' => false,
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'CacheDuration' => 'maxage',
        'MaxAge' => 120,
        'MaxAgePreset' => '120',
        'EnableMustRevalidate' => true,
        'ApplyCacheToChildren' => false,
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
        // Remove scaffolded fields — we replace them with custom versions below.
        // CMS 6 enforces unique field names in FieldList, so these must be removed first.
        $fields->removeByName([
            'OverrideCacheControl',
            'EnableCacheControl',
            'CacheType',
            'CacheDuration',
            'MaxAge',
            'MaxAgePreset',
            'EnableMustRevalidate',
            'ApplyCacheToChildren',
        ]);

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

        // Dynamic label: reflect whether this page inherits from a parent or from site config
        $ancestor = $this->findInheritedCacheSource();
        if ($ancestor) {
            $overrideLabel = 'Override inherited cache settings';
            $overrideDescription = sprintf(
                'This page currently inherits cache settings from "%s". Enable this to set custom cache control for this specific page.',
                $ancestor->Title
            );
        } else {
            $overrideLabel = 'Override site cache settings';
            $overrideDescription = 'Enable this to set custom cache control for this specific page, overriding site-wide settings.';
        }
        $overrideField = CheckboxField::create('OverrideCacheControl', $overrideLabel)
            ->setDescription($overrideDescription);

        // Cache inheritance: "Apply to child pages" checkbox.
        // Only available when enable_cache_inheritance is true in YAML config.
        // This avoids exposing the feature (and its runtime overhead) unless a developer opts in.
        $applyToChildrenField = null;
        if ($this->owner->config()->get('enable_cache_inheritance')) {
            $applyToChildrenField = CheckboxField::create('ApplyCacheToChildren', 'Apply to child pages')
                ->setDescription(
                    'These cache settings will apply to all descendant pages unless they have their own cache control override. '
                    . 'Child pages will show these settings as inherited.'
                );
        }

        $pageHeaderField = HeaderField::create('PageCacheControlHeader', 'Page-Specific Cache Settings', 3);
        $pageInfoField = LiteralField::create('PageCacheControlInfo',
            '<p class="message info">These settings will only apply to this page and override the site-wide cache settings.</p>'
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
        $mustRevalidateField = CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
            ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content (recommended).');

        // Always set field values explicitly so editors see accurate values regardless of
        // whether the fields are inside wrappers or composite fields.
        // Determine the source of effective cache settings for pre-populating form fields.
        // When override is disabled, fields display the values the page will actually inherit,
        // so editors see accurate values before enabling override. Priority order:
        //   1. Page's own override values (when OverrideCacheControl is enabled)
        //   2. Nearest ancestor with ApplyCacheToChildren (when enable_cache_inheritance is on)
        //   3. SiteConfig defaults
        // Reuse $ancestor from override label lookup above
        if ($this->owner->OverrideCacheControl) {
            $source = $this->owner;
        } else {
            $source = $ancestor ?: $siteConfig;
        }
        $enableCacheField->setValue($source->EnableCacheControl);
        $cacheTypeField->setValue($source->CacheType ?: 'public');
        $cacheDurationField->setValue($source->CacheDuration ?: 'maxage');
        $maxAgePresetField->setValue($source->MaxAgePreset ?: '120');
        $maxAgeField->setValue($source->MaxAge ?: 120);
        $mustRevalidateField->setValue($source->EnableMustRevalidate);

        // Apply Display Logic - fields show/hide based on conditions
        // First level: only show when override is enabled
        $pageHeaderField->displayIf('OverrideCacheControl')->isChecked();
        $pageInfoField->displayIf('OverrideCacheControl')->isChecked();
        $enableCacheField->displayIf('OverrideCacheControl')->isChecked();

        if ($applyToChildrenField) {
            $applyToChildrenField->displayIf('OverrideCacheControl')->isChecked()
                ->andIf('EnableCacheControl')->isChecked();
        }

        // Second level: show when override AND cache control are enabled
        // Note: OptionsetFields must be wrapped for display logic to work properly
        $cacheTypeWrapper = Wrapper::create($cacheTypeField);
        $cacheTypeWrapper->displayIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked()->end();

        $cacheDurationWrapper = Wrapper::create($cacheDurationField);
        $cacheDurationWrapper->displayIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked()->end();

        // Third level: only show max-age related fields when duration is set to 'maxage'
        $maxAgePresetField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        // Fourth level: only show custom max-age input when preset is set to 'custom'
        $maxAgeField->displayIf('MaxAgePreset')->isEqualTo('custom')
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $mustRevalidateField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        // Group page-specific settings in a collapsible section
        $advancedFields = [
            $cacheTypeWrapper,
            $cacheDurationWrapper,
            $maxAgePresetField,
            $maxAgeField,
            $mustRevalidateField,
        ];
        if ($applyToChildrenField) {
            $advancedFields[] = $applyToChildrenField;
        }
        $pageCacheControlSection = ToggleCompositeField::create('PageCacheControlSettings', 'Cache-Control Header (Advanced)',
            $advancedFields
        )->setStartClosed(true);

        // Wrap page cache control section to control visibility with display logic
        $pageCacheControlWrapper = Wrapper::create($pageCacheControlSection);
        $pageCacheControlWrapper->displayIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked()->end();

        $cacheControlFields = [
            $headerField,
            $currentCacheControlField,
            $overrideField,
            $pageHeaderField,
            $pageInfoField,
            $enableCacheField,
            $pageCacheControlWrapper,
        ];

        $fields->addFieldsToTab('Root.CacheControl', $cacheControlFields);
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

        // Check for an ancestor that applies its cache settings to children.
        // This is gated by enable_cache_inheritance config — findInheritedCacheSource()
        // returns null immediately when the config is false, adding zero overhead.
        $ancestor = $this->findInheritedCacheSource();
        if ($ancestor) {
            return $ancestor->getCacheControlHeader();
        }

        // Fall back to site config settings
        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig->hasExtension(CacheControlSiteConfigExtension::class)) {
            return $siteConfig->getCacheControlHeader();
        }

        return null;
    }

    /**
     * Find the nearest ancestor that applies its cache settings to children
     *
     * Walks up the page tree looking for the closest ancestor with both
     * OverrideCacheControl and ApplyCacheToChildren enabled. Ancestors that
     * override for themselves only (without ApplyCacheToChildren) are skipped,
     * allowing more distant ancestors to still apply.
     *
     * Returns null immediately if enable_cache_inheritance config is false,
     * avoiding any database queries when the feature is not enabled.
     *
     * Performance: O(d) where d is the tree depth (typically 3-5 levels).
     * Each level requires one Parent() lookup. For a page at depth 3 under
     * an /archive parent with ApplyCacheToChildren, this is just 1 query.
     *
     * @return SiteTree|null The ancestor page to inherit from, or null if
     *                       none found or feature is disabled
     */
    public function findInheritedCacheSource()
    {
        // Early return when cache inheritance is disabled via config.
        // This ensures zero performance overhead for sites that don't use the feature.
        if (!$this->owner->config()->get('enable_cache_inheritance')) {
            return null;
        }

        $parent = $this->owner->Parent();
        while ($parent && $parent->exists()) {
            if ($parent->OverrideCacheControl && $parent->ApplyCacheToChildren) {
                return $parent;
            }
            $parent = $parent->Parent();
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
        }

        // Add must-revalidate if enabled
        if ($this->owner->EnableMustRevalidate) {
            $directives[] = 'must-revalidate';
        }

        return !empty($directives) ? implode(', ', $directives) : null;
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
        if ($this->owner->OverrideCacheControl
            && $this->owner->EnableCacheControl
            && $this->owner->CacheDuration === 'maxage'
            && $this->owner->MaxAgePreset === 'custom'
            && (int)$this->owner->MaxAge < 1
        ) {
            $result->addFieldError('MaxAge', 'Custom max age must be at least 1 second.');
        }
    }

    /**
     * Get a human-readable description of the effective cache control header
     *
     * Shows what header is currently active and where it comes from:
     * - "page-specific setting" when the page has its own override
     * - "inherited from [Page Title]" when inheriting from an ancestor (requires enable_cache_inheritance)
     * - "inherited from site-wide settings" when using SiteConfig defaults
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

        if ($this->owner->OverrideCacheControl) {
            $source = 'This is a <strong>page-specific setting</strong>.';
        } else {
            $ancestor = $this->findInheritedCacheSource();
            if ($ancestor) {
                $source = sprintf(
                    'This is <strong>inherited from &ldquo;%s&rdquo;</strong>.',
                    htmlspecialchars($ancestor->Title)
                );
            } else {
                $source = 'This is <strong>inherited from site-wide settings</strong>.';
            }
        }

        return sprintf(
            '<code>%s</code><br><small>%s</small>',
            htmlspecialchars($header),
            $source
        );
    }
}
