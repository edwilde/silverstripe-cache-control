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

class CacheControlSiteConfigExtension extends DataExtension
{
    use CacheControlTrait;
    private static $db = [
        'EnableCacheControl' => 'Boolean',
        'CacheType' => 'Enum("public,private","public")',
        'EnableMaxAge' => 'Boolean',
        'MaxAge' => 'Int',
        'EnableMustRevalidate' => 'Boolean',
        'EnableNoStore' => 'Boolean',
    ];

    private static $defaults = [
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'EnableMaxAge' => false,
        'MaxAge' => 120,
        'EnableMustRevalidate' => false,
        'EnableNoStore' => false,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab('Root.CacheControl', [
            HeaderField::create('CacheControlHeader', 'Cache-Control Settings', 2),
            LiteralField::create('CacheControlInfo', 
                '<p class="message notice">Control how browsers and CDNs cache your website pages. ' .
                'Enable cache control to improve performance by allowing browsers to store copies of your pages.</p>'
            ),
            
            CheckboxField::create('EnableCacheControl', 'Enable Cache Control')
                ->setDescription('Turn on cache control headers for this site. When disabled, no cache headers will be added.'),
            
            OptionsetField::create('CacheType', 'Cache Type', [
                'public' => 'Public - Allow browsers and CDNs to cache (recommended for public pages)',
                'private' => 'Private - Only allow browser caching, not CDN/proxy caching (for user-specific content)',
            ])
                ->setDescription('Choose who can cache your pages.')
                ->displayIf('EnableCacheControl')->isChecked()->end(),
            
            CheckboxField::create('EnableMaxAge', 'Enable Max Age')
                ->setDescription('Set how long (in seconds) browsers and CDNs can cache your pages before checking for updates.')
                ->displayIf('EnableCacheControl')->isChecked()
                    ->andIf('EnableNoStore')->isNotChecked()
                ->end(),
            
            NumericField::create('MaxAge', 'Max Age (seconds)')
                ->setDescription('Default is 120 seconds (2 minutes). Common values: 60 (1 min), 300 (5 mins), 3600 (1 hour), 86400 (1 day).')
                ->setAttribute('placeholder', '120')
                ->displayIf('EnableCacheControl')->isChecked()
                    ->andIf('EnableMaxAge')->isChecked()
                    ->andIf('EnableNoStore')->isNotChecked()
                ->end(),
            
            CheckboxField::create('EnableMustRevalidate', 'Enable Must Revalidate')
                ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content.')
                ->displayIf('EnableCacheControl')->isChecked()->end(),
            
            CheckboxField::create('EnableNoStore', 'Enable No Store')
                ->setDescription('Prevent any caching of this content. Use for sensitive or frequently changing content. Overrides max-age settings.')
                ->displayIf('EnableCacheControl')->isChecked()->end(),
        ]);
    }

    public function getCacheControlHeader()
    {
        return $this->owner->buildCacheControlHeader();
    }
}
