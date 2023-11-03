<?php

    declare(strict_types = 1);

    namespace Coco\Tests\Unit;

    use Coco\sqlCache\SqlCache;
    use PHPUnit\Framework\TestCase;

final class sqlCacheTest extends TestCase
{

    public $sqlCacheClient = null;

    public function setUp(): void
    {
        $redisHost     = '127.0.0.1';
        $redisPort     = 6379;
        $redisPassword = '';
        $redisDb       = 9;

        $this->sqlCacheClient = new SqlCache(redisHost: $redisHost, prefix: 'phpunit_test');

        $this->sqlCacheClient->setEnable(true);
        $this->sqlCacheClient->setIsAnalysisEnabled(true);
        $this->sqlCacheClient->addIgnoreTable('table_1');
        $this->sqlCacheClient->removeIgnoreTable('table_1');
    }

    public function testA()
    {

        $this->sqlCacheClient->clearAllData();
        $this->sqlCacheClient->clearAllCache();

        $sql = "SELECT 
  * 
FROM
  table_1 tp 
  INNER JOIN table_2 tc 
    ON tp.cno = tc.cid 
WHERE tc.cname = '手机数码';";

        $data = $this->sqlCacheClient->autoCache($sql, function () {
            return ['name ' => ' hello'];
        });

        $this->assertTrue(true);
    }

    public function testB()
    {
        $sql1 = "DELETE 
FROM
  `smartpanel`.`table_1` 
WHERE `id` = 1";

        $this->sqlCacheClient->clearBySql($sql1);

        $this->assertTrue(true);
    }

    public function testC()
    {
        $this->sqlCacheClient->getAnalysisData();

        $this->assertTrue(true);
    }
}
