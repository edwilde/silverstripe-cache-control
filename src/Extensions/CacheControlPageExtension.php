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

use Edwilde\CacheControl\SharedMaxAge;
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
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
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
     * Max-age in seconds to use when a page has unpublished draft changes.
     *
     * When a page has been saved but not published, this value replaces the normal
     * max-age to ensure CDN caches expire quickly when the page is eventually published.
     *
     * Controlled by the EnableDraftCacheReduction checkbox in SiteConfig.
     * Override via YAML config:
     *
     *     SilverStripe\CMS\Model\SiteTree:
     *       draft_cache_max_age: 30
     *
     * @config
     * @var int
     */
    private static int $draft_cache_max_age = 10;

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
        'SharedMaxAgePreset' => SharedMaxAge::PRESET_ENUM,
        'SharedMaxAge' => 'Int',
        'EnableMustRevalidate' => 'Boolean',
        'StaleWhileRevalidatePreset' => StaleDirectives::REFRESH_PRESET_ENUM,
        'StaleWhileRevalidate' => 'Int',
        'StaleIfErrorPreset' => StaleDirectives::ERROR_PRESET_ENUM,
        'StaleIfError' => 'Int',
        'ApplyCacheToChildren' => 'Boolean',
        'HasPendingDraftChanges' => 'Boolean',
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
        'SharedMaxAgePreset' => SharedMaxAge::PRESET_OFF,
        'SharedMaxAge' => 0,
        'EnableMustRevalidate' => true,
        'StaleWhileRevalidatePreset' => StaleDirectives::PRESET_OFF,
        'StaleWhileRevalidate' => 0,
        'StaleIfErrorPreset' => StaleDirectives::PRESET_OFF,
        'StaleIfError' => 0,
        'ApplyCacheToChildren' => false,
        'HasPendingDraftChanges' => false,
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
            'SharedMaxAgePreset',
            'SharedMaxAge',
            'EnableMustRevalidate',
            'StaleWhileRevalidatePreset',
            'StaleWhileRevalidate',
            'StaleIfErrorPreset',
            'StaleIfError',
            'ApplyCacheToChildren',
            'HasPendingDraftChanges',
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
                    . 'Child pages will show these settings as inherited. '
                    . 'Any grace periods set here apply to every descendant page too.'
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
        ])->setDescription(
            'How long visitors\' browsers keep a copy of the page. CDNs use the same time unless '
            . 'you set a CDN cache duration below.'
        );
        $maxAgeField = NumericField::create('MaxAge', 'Custom Max Age (seconds)')
            ->setDescription('Enter a custom cache duration in seconds.')
            ->setAttribute('placeholder', '120');
        $sharedMaxAgeInfoField = SharedMaxAge::infoField('PageSharedMaxAgeInfo');
        $sharedMaxAgePresetField = DropdownField::create(
            'SharedMaxAgePreset',
            'CDN Cache Duration',
            SharedMaxAge::presetOptions()
        )->setDescription(
            'How long the CDN may keep its copy. Browsers ignore this. Usually longer than the Max '
            . 'Age Duration: a shorter time makes the CDN fetch a fresh copy more often than browsers do.'
        );
        $sharedMaxAgeField = NumericField::create('SharedMaxAge', 'Custom CDN Cache Duration (seconds)')
            ->setDescription('Enter a custom CDN cache duration in seconds, up to one year (31536000).')
            ->setAttribute('placeholder', '604800');
        $staleInfoField = StaleDirectives::infoField('PageStaleDirectivesInfo');
        $staleWhileRevalidatePresetField = DropdownField::create(
            'StaleWhileRevalidatePreset',
            'Refresh Grace Period',
            StaleDirectives::refreshPresetOptions()
        )->setDescription('How long caches may serve the expired copy while fetching a fresh one in the background.');
        $staleWhileRevalidateField = NumericField::create('StaleWhileRevalidate', 'Custom Refresh Grace Period (seconds)')
            ->setDescription('Enter a custom refresh grace period in seconds, up to one year (31536000).')
            ->setAttribute('placeholder', '86400');
        $staleIfErrorPresetField = DropdownField::create(
            'StaleIfErrorPreset',
            'Error Grace Period',
            StaleDirectives::errorPresetOptions()
        )->setDescription('How long caches may keep serving the stored copy while the server returns errors.');
        $staleIfErrorField = NumericField::create('StaleIfError', 'Custom Error Grace Period (seconds)')
            ->setDescription('Enter a custom error grace period in seconds, up to one year (31536000).')
            ->setAttribute('placeholder', '604800');
        $mustRevalidateField = CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
            ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content. '
                . 'Not available while a grace period is set, which asks caches to do the opposite.');

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
        $sharedMaxAgePresetField->setValue($source->SharedMaxAgePreset ?: SharedMaxAge::PRESET_OFF);
        $sharedMaxAgeField->setValue((int)$source->SharedMaxAge);
        $mustRevalidateField->setValue($source->EnableMustRevalidate);
        $staleWhileRevalidatePresetField->setValue($source->StaleWhileRevalidatePreset ?: StaleDirectives::PRESET_OFF);
        $staleWhileRevalidateField->setValue((int)$source->StaleWhileRevalidate);
        $staleIfErrorPresetField->setValue($source->StaleIfErrorPreset ?: StaleDirectives::PRESET_OFF);
        $staleIfErrorField->setValue((int)$source->StaleIfError);

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

        $sharedMaxAgePresetField->displayIf('CacheType')->isEqualTo('public')
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $sharedMaxAgeField->displayIf('SharedMaxAgePreset')->isEqualTo(SharedMaxAge::PRESET_CUSTOM)
            ->andIf('CacheType')->isEqualTo('public')
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $staleWhileRevalidatePresetField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $staleWhileRevalidateField->displayIf('StaleWhileRevalidatePreset')->isEqualTo(StaleDirectives::PRESET_CUSTOM)
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $staleIfErrorPresetField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        $staleIfErrorField->displayIf('StaleIfErrorPreset')->isEqualTo(StaleDirectives::PRESET_CUSTOM)
            ->andIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked();

        // Hidden while either grace period is set, since the two cancel each other out.
        $mustRevalidateField->displayIf('CacheDuration')->isEqualTo('maxage')
            ->andIf('OverrideCacheControl')->isChecked()
            ->andIf('EnableCacheControl')->isChecked()
            ->andIf('StaleWhileRevalidatePreset')->isEqualTo(StaleDirectives::PRESET_OFF)
            ->andIf('StaleIfErrorPreset')->isEqualTo(StaleDirectives::PRESET_OFF);

        // Group page-specific settings in a collapsible section
        $pageCacheControlSection = ToggleCompositeField::create('PageCacheControlSettings', 'Cache-Control Header (Advanced)',
            [
                $cacheTypeWrapper,
                $cacheDurationWrapper,
                $maxAgePresetField,
                $maxAgeField,
                $sharedMaxAgeInfoField,
                $sharedMaxAgePresetField,
                $sharedMaxAgeField,
                $staleInfoField,
                StaleDirectives::privateNoticeField('PageStaleDirectivesPrivateNotice'),
                $staleWhileRevalidatePresetField,
                $staleWhileRevalidateField,
                $staleIfErrorPresetField,
                $staleIfErrorField,
                $mustRevalidateField,
            ]
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
        if ($applyToChildrenField) {
            $cacheControlFields[] = $applyToChildrenField;
        }

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
     * Flag the Live record when a page has unpublished draft changes.
     *
     * When an editor saves a page without publishing, this sets HasPendingDraftChanges=true
     * on the Live record. This flag is read at request time to reduce the cache max-age,
     * avoiding a per-request version comparison query.
     */
    public function onAfterWrite()
    {
        // Only flag when the page is already published and now has draft differences
        if ($this->owner->isPublished() && $this->owner->isModifiedOnDraft()) {
            $this->updateLiveDraftFlag(true);
        }
    }

    /**
     * Clear the draft changes flag when a page is published.
     *
     * Publishing copies draft to live, so the content is now in sync.
     * The flag is cleared explicitly for safety, though the copy operation
     * also carries the draft's default false value.
     */
    public function onAfterPublish()
    {
        $this->updateLiveDraftFlag(false);
    }

    /**
     * Clear the draft changes flag when draft changes are discarded.
     */
    public function onAfterRevertToLive()
    {
        $this->updateLiveDraftFlag(false);
    }

    /**
     * Update the HasPendingDraftChanges flag on the Live record.
     *
     * Uses SQLUpdate to write directly to the Live table without triggering
     * a full DataObject write cycle or versioning hooks.
     *
     * @param bool $hasPendingChanges Whether the page has pending draft changes
     */
    private function updateLiveDraftFlag(bool $hasPendingChanges): void
    {
        $table = $this->owner->stageTable($this->owner->baseTable(), Versioned::LIVE);
        SQLUpdate::create($table)
            ->addWhere(['ID' => $this->owner->ID])
            ->assign('HasPendingDraftChanges', $hasPendingChanges ? 1 : 0)
            ->execute();
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

            // CDN cache duration follows max-age, only for a public cache type
            if ($this->owner->CacheType === 'public') {
                $sharedMaxAge = SharedMaxAge::forSource($this->owner);
                if ($sharedMaxAge > 0) {
                    $directives[] = 's-maxage=' . $sharedMaxAge;
                }
            }

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
        if (!$this->owner->OverrideCacheControl
            || !$this->owner->EnableCacheControl
            || $this->owner->CacheDuration !== 'maxage'
        ) {
            return;
        }

        if ($this->owner->MaxAgePreset === 'custom' && (int)$this->owner->MaxAge < 1) {
            $result->addFieldError('MaxAge', 'Custom max age must be at least 1 second.');
        }

        SharedMaxAge::validate($this->owner, $result);
        StaleDirectives::validate($this->owner, $result);
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

        $caveat = '';
        if (!$this->owner->OverrideCacheControl || $this->owner->EnableCacheControl) {
            $caveat = '<br><small>Note: Silverstripe may override this at runtime for pages with forms, active sessions, or restricted access.</small>';
        }

        // Check if draft cache reduction is active — use isModifiedOnDraft() for CMS context
        // (the HasPendingDraftChanges flag lives on the Live record, but we're on Draft stage here)
        $draftNotice = '';
        $siteConfigForDraft = SiteConfig::current_site_config();
        if ($siteConfigForDraft->EnableDraftCacheReduction
            && $this->owner->isPublished()
            && $this->owner->isModifiedOnDraft()
        ) {
            $draftMaxAge = $this->owner->config()->get('draft_cache_max_age');
            $draftNotice = sprintf(
                '<br><span class="message warning">Cache time temporarily reduced to %d seconds '
                . '— this page has unpublished changes. Publish to restore normal cache time.</span>',
                $draftMaxAge
            );
        }

        return sprintf(
            '<code>%s</code><br><small>%s</small>%s%s',
            htmlspecialchars($header),
            $source,
            $caveat,
            $draftNotice
        );
    }
}
