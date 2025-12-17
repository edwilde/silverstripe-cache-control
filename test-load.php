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

echo "Loading SiteConfig...\n";
try {
    $config = SiteConfig::current_site_config();
    echo "Success!\n";
    
    echo "Getting CMS fields...\n";
    $fields = $config->getCMSFields();
    echo "Success!\n";
    
    $tab = $fields->fieldByName('Root.CacheControl');
    if ($tab) {
        echo "Cache Control tab exists\n";
        echo "Field count: " . $tab->FieldList()->count() . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
