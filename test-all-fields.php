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

echo "Fields in CacheControl tab:\n";
$cacheTab = $fields->findOrMakeTab('Root.CacheControl');
foreach ($cacheTab->Fields() as $field) {
    $name = $field->getName();
    echo "  - $name (" . get_class($field) . ")\n";
    
    // Check for display logic data attributes
    if (method_exists($field, 'FieldHolder')) {
        $rendered = $field->FieldHolder();
        if (strpos($rendered, 'data-display-logic') !== false) {
            echo "    ✓ Has DisplayLogic\n";
        } else {
            echo "    ✗ No DisplayLogic\n";
        }
    }
}
