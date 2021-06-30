<?php

require "vendor/autoload.php";

use Inbenta\GoogleConnector\GoogleConnector;

header('Content-Type: application/json');

// Instance new Connector
$appPath = __DIR__ . '/';

$app = new GoogleConnector($appPath);
$inbentaResponse = $app->handleRequest();
if (isset($inbentaResponse)) {
    echo json_encode($inbentaResponse);
}
