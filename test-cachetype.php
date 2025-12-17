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

$cacheType = $fields->dataFieldByName('CacheType');
$rendered = $cacheType->FieldHolder();

if (preg_match_all('/data-display-logic-[a-z-]+="[^"]*"/', $rendered, $matches)) {
    echo "CacheType data attributes:\n";
    foreach ($matches[0] as $attr) {
        $decoded = html_entity_decode($attr);
        echo "  $decoded\n";
    }
} else {
    echo "No DisplayLogic data attributes found on CacheType field!\n";
}
