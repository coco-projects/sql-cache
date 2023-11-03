<?php

    use Symfony\Component\Cache\CacheItem;

    use Symfony\Component\Cache\Adapter\RedisAdapter;
    use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

    require '../vendor/autoload.php';

    $redisHost     = '127.0.0.1';
    $redisPort     = 6379;
    $redisPassword = '';
    $redisDb       = 9;

    $sqlCacheClient = new \Coco\sqlCache\SqlCache(redisHost: $redisHost, prefix: 'test_db');
    $sqlCacheClient->setEnable(false);
    $sqlCacheClient->setIsAnalysisEnabled(true);
    //    $sqlCacheClient->addIgnoreTable('table_1');

    $sql = "SELECT 
  * 
FROM
  table_1 tp 
  INNER JOIN table_2 tc 
    ON tp.cno = tc.cid 
WHERE tc.cname = 'goods_type' ;";

    $data = $sqlCacheClient->autoCache($sql, function() {
        echo 'cache1111111111111111111';
        echo PHP_EOL;
        return ['name ' => ' hello'];
    });

    print_r($data);

    $sql = "SELECT 
  * 
FROM
  table_3 tp 
  INNER JOIN table_4 tc 
    ON tp.cno = tc.cid 
WHERE tc.cname = 'goods_type' ;";

    $data = $sqlCacheClient->autoCache($sql, function() {
        echo 'cache3333333333333333333';
        echo PHP_EOL;
        return ['name11 ' => ' hello22'];
    });

    print_r($data);
