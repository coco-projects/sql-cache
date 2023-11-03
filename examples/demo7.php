<?php

    require '../vendor/autoload.php';

    $redisHost     = '127.0.0.1';
    $redisPort     = 6379;
    $redisPassword = '';
    $redisDb       = 9;

    $sqlCacheClient = new \Coco\sqlCache\SqlCache(redisHost: $redisHost,prefix: 'test_db');
    $sqlCacheClient->setIsAnalysisEnabled(true);

    $sqlCacheClient->clearAllCache();

    $sqlCacheClient->clearAllData();
