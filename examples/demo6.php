<?php

    use Coco\sqlCache\SqlCache;

    require '../vendor/autoload.php';

    $redisHost     = '127.0.0.1';
    $redisPort     = 6379;
    $redisPassword = '';
    $redisDb       = 9;

    $sqlCacheClient = new SqlCache(redisHost: $redisHost, prefix: 'test_db');
    $sqlCacheClient->setIsAnalysisEnabled(true);

    $sql1 = "DELETE 
FROM
  `smartpanel`.`table_1` 
WHERE `id` = 1";

    $sql2 = "UPDATE 
  `smartpanel`.`table_2` 
SET
  `id` = 'id',
  `ids` = 'ids',
  `lang_code` = 'lang_code',
  `slug` = 'slug',
  `value` = 'value' 
WHERE `id` = 'id' ;

";

    $sql3 = "INSERT INTO `smartpanel`.`table_1` (
  `id`,
  `ids`,
  `lang_code`,
  `slug`,
  `value`
) 
VALUES
  (
    'id',
    'ids',
    'lang_code',
    'slug',
    'value'
  ) ;


";

    $sqlCacheClient->clearBySql($sql1);
    $sqlCacheClient->clearBySql($sql2);
    $sqlCacheClient->clearBySql($sql3);