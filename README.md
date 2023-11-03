
# sql cache

##### Based on the lexical analysis of "update," "select," "insert," and "delete" SQL statements, an automatic caching strategy is implemented. The strategy involves caching the data when executing a select operation and associating the table name contained in the SQL statement with the corresponding cached records. When executing update, insert, or delete operations, the table name from the SQL statement is extracted, and any cached records containing this table name from previous select operations are deleted. This strategy enables seamless caching without delay and eliminates the need to worry about data synchronization issues. It is particularly effective for tables with infrequent data modifications. For tables with frequent modifications, they can be ignored by configuring the strategy accordingly.

---

### Here's a quick example:

```php
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
    $sqlCacheClient->setEnable(true);
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
        return ['name ' => ' hello'];
    });

    print_r($data);

```

```php
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

```

```php
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

```

```php
<?php

    use Coco\sqlCache\SqlCache;

    require '../vendor/autoload.php';

    $redisHost     = '127.0.0.1';
    $redisPort     = 6379;
    $redisPassword = '';
    $redisDb       = 9;

    $sqlCacheClient = new SqlCache(redisHost: $redisHost, prefix: 'test_db');
    $sqlCacheClient->setIsAnalysisEnabled(true);

    $data = $sqlCacheClient->getAnalysisData();
    print_r($data);
/*
 * 
Array
(
    [sql] => Array
        (
            [0] => Array
                (
                    [db_read] => 1
                    [total_read] => 4
                    [tables] => table_1,table_2
                    [sql] => SELECT 
  * 
FROM
  table_1 tp 
  INNER JOIN table_2 tc 
    ON tp.cno = tc.cid 
WHERE tc.cname = 'goods_type' ;
                )

            [1] => Array
                (
                    [db_read] => 1
                    [total_read] => 4
                    [tables] => table_3,table_4
                    [sql] => SELECT 
  * 
FROM
  table_3 tp 
  INNER JOIN table_4 tc 
    ON tp.cno = tc.cid 
WHERE tc.cname = 'goods_type' ;
                )

        )

    [table] => Array
        (
            [table_4] => Array
                (
                    [total_read] => 4
                    [db_read] => 1
                )

            [table_1] => Array
                (
                    [invalidate_delete] => 3
                    [invalidate_insert] => 3
                    [total_read] => 4
                    [db_read] => 1
                )

            [table_3] => Array
                (
                    [total_read] => 4
                    [db_read] => 1
                )

            [table_2] => Array
                (
                    [invalidate_update] => 3
                    [total_read] => 4
                    [db_read] => 1
                )

        )

)

 
*/

```

## Installation

You can install the package via composer:

```bash
composer require coco-project/sql-cache
```

## Testing

``` bash
composer test
```

## License

---

MIT
