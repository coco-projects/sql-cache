<?php

    use Coco\sqlCache\SqlCache;

    require_once '../vendor/autoload.php';

    $sqlCacheClient = new SqlCache(
        redisHost: '127.0.0.1',
        redisPort: 6379,
        redisPassword: '',
        redisDb: 9,
        prefix: 'default_db'
    );

// 可选配置
    $sqlCacheClient
        ->setEnable(true)
        ->setExpiration(300)
        ->setIsAnalysisEnabled(true)
        ->setMaxRetries(3)
        ->setRetryDelay(100);

// 忽略某些表，不参与缓存
    $sqlCacheClient->addIgnoreTable('logs');

// 查询自动缓存
    $sql = 'SELECT * FROM users WHERE id = 1';

    $data = $sqlCacheClient->autoCache($sql, function () {
        // 这里写真实数据库查询逻辑
        return [
            'id'   => 1,
            'name' => 'Tom',
        ];
    });

    var_dump($data);

// 数据变更后按 SQL 清理相关表缓存
    $sqlCacheClient->clearBySql("UPDATE users SET name = 'Jerry' WHERE id = 1");

// 批量清理
    $sqlCacheClient->clearBySqls([
        "UPDATE users SET name = 'A' WHERE id = 2",
        "DELETE FROM orders WHERE id = 10",
    ]);

// 获取分析数据
    $analysis = $sqlCacheClient->getAnalysisData();
    var_dump($analysis);

// 清理当前 prefix 下所有缓存
    $sqlCacheClient->clearAllCache();

// 清理当前 prefix 下所有数据（包括分析数据）
    $sqlCacheClient->clearAllData();

// 判断是否处于降级模式
    if ($sqlCacheClient->isInFallbackMode()) {
        echo "Redis 不可用，当前处于降级模式\n";
    }