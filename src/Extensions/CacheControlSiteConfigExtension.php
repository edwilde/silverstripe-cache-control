<?php

namespace Edwilde\CacheControls\Extensions;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\DataExtension;
use UncleCheese\DisplayLogic\Forms\Wrapper;

class CacheControlSiteConfigExtension extends DataExtension
{
    private static $db = [
        'EnableCacheControl' => 'Boolean',
        'CacheType' => 'Enum("public,private","public")',
        'CacheDuration' => 'Enum("maxage,nostore","maxage")',
        'MaxAge' => 'Int',
        'EnableMustRevalidate' => 'Boolean',
    ];

    private static $defaults = [
        'EnableCacheControl' => false,
        'CacheType' => 'public',
        'CacheDuration' => 'maxage',
        'MaxAge' => 120,
        'EnableMustRevalidate' => false,
    ];

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
            ->setDescription('Force browsers to check with the server when cache expires, rather than using stale content.');

        // Wrap OptionsetFields to ensure display logic works correctly
        $cacheTypeWrapper = Wrapper::create($cacheTypeField);
        $cacheTypeWrapper->displayIf('EnableCacheControl')->isChecked()->end();

        $cacheDurationWrapper = Wrapper::create($cacheDurationField);
        $cacheDurationWrapper->displayIf('EnableCacheControl')->isChecked()->end();

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

    public function getCacheControlHeader()
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
}
