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
echo "EnableCacheControl value: " . var_export($config->EnableCacheControl, true) . "\n";

$fields = $config->getCMSFields();
$checkbox = $fields->dataFieldByName('EnableCacheControl');
echo "Checkbox value: " . var_export($checkbox->Value(), true) . "\n";
echo "Checkbox checked: " . var_export($checkbox->Value() ? 'yes' : 'no', true) . "\n";
