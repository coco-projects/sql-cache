<?php

    use Symfony\Component\Cache\CacheItem;

    use Symfony\Component\Cache\Adapter\RedisAdapter;
    use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

    require '../vendor/autoload.php';

    $redisHost     = '127.0.0.1';
    $redisPort     = 6379;
    $redisPassword = '';
    $redisDb       = 9;

    $redisClient = new \Redis();
    $redisClient->connect($redisHost, $redisPort);
    $redisPassword and $redisClient->auth($redisPassword);
    $redisClient->select($redisDb);

    //    $cacheManager = new RedisAdapter($redisClient, 'sql_parser');
    $cacheManager = new RedisTagAwareAdapter($redisClient, 'sql_parser');

    $cacheManager->invalidateTags(['tag3']);