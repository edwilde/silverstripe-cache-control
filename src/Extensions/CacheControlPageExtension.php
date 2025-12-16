<?php

namespace Edwilde\CacheControls\Extensions;

use Edwilde\CacheControls\Traits\CacheControlTrait;
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
    use CacheControlTrait;
    private static $db = [
        'OverrideCacheControl' => 'Boolean',
        'EnableCacheControl' => 'Boolean',
        'CacheType' => 'Enum("public,private","public")',
        'EnableMaxAge' => 'Boolean',
        'MaxAge' => 'Int',
        'EnableMustRevalidate' => 'Boolean',
        'EnableNoStore' => 'Boolean',
    ];

    private static $defaults = [
        'OverrideCacheControl' => false,
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'EnableMaxAge' => false,
        'MaxAge' => 120,
        'EnableMustRevalidate' => false,
        'EnableNoStore' => false,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $effectiveHeader = $this->owner->getEffectiveCacheControlDescription();
        
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
        ]);

        $fields->addFieldsToTab('Root.CacheControl', [
            HeaderField::create('PageCacheControlHeader', 'Page-Specific Cache Settings', 3)
                ->displayIf('OverrideCacheControl')->isChecked()->end(),
            
            LiteralField::create('PageCacheControlInfo', 
                '<p class="message info">These settings will only apply to this page and override the site-wide cache settings.</p>'
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
            
            CheckboxField::create('EnableMaxAge', 'Enable Max Age')
                ->setDescription('Set how long (in seconds) browsers and CDNs can cache this page before checking for updates.')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                    ->andIf('EnableNoStore')->isNotChecked()
                ->end(),
            
            NumericField::create('MaxAge', 'Max Age (seconds)')
                ->setDescription('Default is 120 seconds (2 minutes). Common values: 60 (1 min), 300 (5 mins), 3600 (1 hour), 86400 (1 day).')
                ->setAttribute('placeholder', '120')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                    ->andIf('EnableMaxAge')->isChecked()
                    ->andIf('EnableNoStore')->isNotChecked()
                ->end(),
            
            CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
                ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content.')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                ->end(),
            
            CheckboxField::create('EnableNoStore', 'Enable No Store')
                ->setDescription('Prevent any caching of this content. Use for sensitive or frequently changing content. Overrides max-age settings.')
                ->displayIf('OverrideCacheControl')->isChecked()
                    ->andIf('EnableCacheControl')->isChecked()
                ->end(),
        ]);
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
        return $this->owner->buildCacheControlHeader();
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
