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

    //    $client       = RedisAdapter::createConnection('redis://localhost');
    //    $cacheManager = new RedisTagAwareAdapter($client);

    $bots = $cacheManager->get('tab_name1', function($item) {
        $item->tag('tag1');
        $item->tag('tag2');

        $i = 100;
        return 'John' . $i;
    });
    $bots = $cacheManager->get('tab_name2', function($item) {
        $item->tag('tag3');
        $item->tag('tag2');

        $i = 200;
        return 'John' . $i;
    });
    $bots = $cacheManager->get('tab_name3', function($item) {
        $item->tag('tag3');
        $item->tag('tag4');

        $i = 300;
        return 'John' . $i;
    });

