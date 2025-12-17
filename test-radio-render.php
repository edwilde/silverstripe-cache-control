<?php

use SilverStripe\Core\Environment;
use SilverStripe\SiteConfig\SiteConfig;

$baseDir = __DIR__ . '/../../nzta-ap';
require_once $baseDir . '/vendor/autoload.php';

$_SERVER['HTTP_HOST'] = 'agent.nzta.local';
$_SERVER['REQUEST_URI'] = 'test';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
Environment::setEnv('SS_DATABASE_NAME', 'SS_nzta_ap');

require_once $baseDir . '/vendor/silverstripe/framework/src/includes/autoload.php';

$kernel = new \SilverStripe\Core\CoreKernel($baseDir);
$app = new \SilverStripe\Control\HTTPApplication($kernel);
$kernel->boot(false);

$config = SiteConfig::current_site_config();
$fields = $config->getCMSFields();

echo "=== Database Values ===\n";
echo "EnableCacheControl: " . var_export($config->EnableCacheControl, true) . "\n";
echo "CacheType: " . var_export($config->CacheType, true) . "\n";
echo "CacheDuration: " . var_export($config->CacheDuration, true) . "\n";
echo "MaxAge: " . var_export($config->MaxAge, true) . "\n\n";

$cacheDuration = $fields->dataFieldByName('CacheDuration');
$maxAge = $fields->dataFieldByName('MaxAge');

echo "=== CacheDuration Field ===\n";
if ($cacheDuration) {
    $rendered = $cacheDuration->FieldHolder();
    
    // Extract data attributes from HTML
    if (preg_match_all('/data-display-logic-[a-z-]+="[^"]*"/', $rendered, $matches)) {
        echo "Data attributes in HTML:\n";
        foreach ($matches[0] as $attr) {
            $decoded = html_entity_decode($attr);
            echo "  $decoded\n";
        }
    }
}

echo "\n=== MaxAge Field ===\n";
if ($maxAge) {
    echo "Field Attributes:\n";
    $attrs = $maxAge->getAttributes();
    foreach ($attrs as $key => $val) {
        if (strpos($key, 'data-') === 0) {
            echo "  $key: " . (is_string($val) ? $val : json_encode($val)) . "\n";
        }
    }
    
    echo "\nRendered HTML (first 500 chars):\n";
    $rendered = $maxAge->FieldHolder();
    echo substr($rendered, 0, 500) . "...\n";
    
    // Extract data attributes from HTML
    if (preg_match_all('/data-display-logic-[a-z-]+="[^"]*"/', $rendered, $matches)) {
        echo "\nData attributes in HTML:\n";
        foreach ($matches[0] as $attr) {
            echo "  $attr\n";
        }
    }
}
