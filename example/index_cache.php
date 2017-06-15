<?php
require_once '../vendor/autoload.php';

$cache = new \PHP\Cache\Cache();
$cache->start();

require_once 'index.php';