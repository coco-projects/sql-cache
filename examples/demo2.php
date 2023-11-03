<?php

    use Symfony\Component\Cache\Adapter\RedisAdapter;

    require '../vendor/autoload.php';

    $redisHost     = '127.0.0.1';
    $redisPort     = 6379;
    $redisPassword = '';
    $redisDb       = 9;

    $redisClient = new \Redis();
    $redisClient->connect($redisHost, $redisPort);
    $redisPassword and $redisClient->auth($redisPassword);
    $redisClient->select($redisDb);

    $cacheManager = new RedisAdapter($redisClient, 'queue_');

    $len = 10;
    for ($i = 0; $i < $len; $i++)
    {
        $redisClient->hMSet('user:' . $i, [
            'name' => 'John' . $i,
            'age'  => 30 + $i,
            'city' => 'New York' . $i,
        ]);
    }

    $re = $redisClient->hMGet('user:1', ['name']);
    print_r($re);
