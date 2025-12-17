<?php

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\SiteConfig\SiteConfig;

class CacheControlPageExtension extends DataExtension
{
    private static $db = [
        'OverrideCacheControl' => 'Boolean',
        'EnableCacheControl' => 'Boolean',
        'CacheType' => 'Enum("public,private","public")',
        'CacheDuration' => 'Enum("maxage,nostore","maxage")',
        'MaxAge' => 'Int',
        'EnableMustRevalidate' => 'Boolean',
    ];

    private static $defaults = [
        'OverrideCacheControl' => false,
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'CacheDuration' => 'maxage',
        'MaxAge' => 120,
        'EnableMustRevalidate' => false,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $effectiveHeader = $this->owner->getEffectiveCacheControlDescription();
        
        // Get site config for pre-filling
        $siteConfig = SiteConfig::current_site_config();
        
        $fields->addFieldsToTab('Root.CacheControl', [
            HeaderField::create('CacheControlHeader', 'Page Cache-Control Settings', 2),
            
            LiteralField::create('CurrentCacheControl', 
                '<div class="message notice">' .
                '<strong>Current Cache-Control Header:</strong><br>' .
                $effectiveHeader .
                '</div>'
            ),
            
            CheckboxField::create('OverrideCacheControl', 'Override Site Cache Settings')
                ->setDescription('Enable this to set custom cache control for this specific page, overriding site-wide settings.'),
            
            HeaderField::create('PageCacheControlHeader', 'Page-Specific Cache Settings', 3)
                ->displayIf('OverrideCacheControl')->isChecked()->end(),
            
            LiteralField::create('PageCacheControlInfo', 
                '<p class="message info">These settings will only apply to this page and override the site-wide cache settings. Values are pre-filled with current site defaults.</p>'
            )
                ->displayIf('OverrideCacheControl')->isChecked()->end(),
            
            CheckboxField::create('EnableCacheControl', 'Enable Cache Control for this Page')
                ->setDescription('Turn on cache control headers for this page.')
                ->displayIf('OverrideCacheControl')->isChecked()->end(),
            
            OptionsetField::create('CacheType', 'Cache Type', [
                'public' => 'Public - Allow browsers and CDNs to cache (recommended for public pages)',
                'private' => 'Private - Only allow browser caching, not CDN/proxy caching (for user-specific content)',
            ])
                ->setDescription('Choose who can cache this page.')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                ->end(),
            
            OptionsetField::create('CacheDuration', 'Cache Duration', [
                'maxage' => 'Cache with Max Age - Allow caching for a specified time',
                'nostore' => 'No Store - Prevent all caching (for sensitive or frequently changing content)',
            ])
                ->setDescription('Choose how long content can be cached.')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                ->end(),
            
            NumericField::create('MaxAge', 'Max Age (seconds)')
                ->setDescription('Default is 120 seconds (2 minutes). Common values: 60 (1 min), 300 (5 mins), 3600 (1 hour), 86400 (1 day).')
                ->setAttribute('placeholder', '120')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                    ->andIf('CacheDuration')->isEqualTo('maxage')
                ->end(),
            
            CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
                ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content.')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                    ->andIf('CacheDuration')->isEqualTo('maxage')
                ->end(),
        ]);
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        
        // Pre-fill with site settings when override is first enabled
        if ($this->owner->isChanged('OverrideCacheControl') && $this->owner->OverrideCacheControl) {
            $siteConfig = SiteConfig::current_site_config();
            
            // Only pre-fill if values haven't been set
            if (!$this->owner->getField('EnableCacheControl')) {
                $this->owner->EnableCacheControl = $siteConfig->EnableCacheControl;
            }
            if (!$this->owner->getField('CacheType')) {
                $this->owner->CacheType = $siteConfig->CacheType ?: 'public';
            }
            if (!$this->owner->getField('CacheDuration')) {
                $this->owner->CacheDuration = $siteConfig->CacheDuration ?: 'maxage';
            }
            if (!$this->owner->getField('MaxAge')) {
                $this->owner->MaxAge = $siteConfig->MaxAge ?: 120;
            }
            if (!$this->owner->getField('EnableMustRevalidate')) {
                $this->owner->EnableMustRevalidate = $siteConfig->EnableMustRevalidate;
            }
        }
    }

    public function getCacheControlHeader()
    {
        if ($this->owner->OverrideCacheControl) {
            return $this->getPageCacheControlHeader();
        }

        $siteConfig = SiteConfig::current_site_config();
        if ($siteConfig->hasExtension(CacheControlSiteConfigExtension::class)) {
            return $siteConfig->getCacheControlHeader();
        }

        return null;
    }

    private function getPageCacheControlHeader()
    {
        if (!$this->owner->EnableCacheControl) {
            return null;
        }

        $directives = [];

        if ($this->owner->CacheDuration === 'nostore') {
            $directives[] = 'no-store';
            return implode(', ', $directives);
        }

        if ($this->owner->CacheType) {
            $directives[] = $this->owner->CacheType;
        }

        if ($this->owner->CacheDuration === 'maxage') {
            $maxAge = (int)$this->owner->MaxAge ?: 120;
            $directives[] = 'max-age=' . $maxAge;
        }

        if ($this->owner->EnableMustRevalidate) {
            $directives[] = 'must-revalidate';
        }

        return !empty($directives) ? implode(', ', $directives) : null;
    }

    public function getEffectiveCacheControlDescription()
    {
        $header = $this->owner->getCacheControlHeader();
        
        if (!$header) {
            return 'No cache control is currently set for this page. ' .
                   'Browsers will use their default caching behavior.';
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
