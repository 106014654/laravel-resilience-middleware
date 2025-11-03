<?php

/**
 * 测试Redis和MySQL监控功能
 */

// 简化的Redis连接测试
class SimpleRedisMonitor
{
    public function getRedisMemoryUsage()
    {
        try {
            // 模拟Redis连接检查
            echo "正在检查Redis连接...\n";

            // 这里可以替换为实际的Redis连接代码
            // $redis = new Redis();
            // $redis->connect('127.0.0.1', 6379);
            // $info = $redis->info();

            // 模拟返回数据
            $mockInfo = [
                'used_memory' => 1048576 * 50,  // 50MB
                'maxmemory' => 1048576 * 100,   // 100MB
            ];

            if (isset($mockInfo['used_memory'], $mockInfo['maxmemory']) && $mockInfo['maxmemory'] > 0) {
                $memoryUsage = ($mockInfo['used_memory'] / $mockInfo['maxmemory']) * 100;

                echo "Redis内存使用情况:\n";
                echo "- 已用内存: " . round($mockInfo['used_memory'] / 1024 / 1024, 2) . " MB\n";
                echo "- 最大内存: " . round($mockInfo['maxmemory'] / 1024 / 1024, 2) . " MB\n";
                echo "- 使用率: " . round($memoryUsage, 2) . "%\n";

                return round($memoryUsage, 2);
            }

            return null;
        } catch (Exception $e) {
            echo "Redis连接失败: " . $e->getMessage() . "\n";
            return null;
        }
    }
}

// 简化的MySQL连接测试
class SimpleMysqlMonitor
{
    public function getMysqlResourceUsage()
    {
        try {
            echo "正在检查MySQL连接...\n";

            // 这里可以替换为实际的MySQL连接代码
            // $pdo = new PDO('mysql:host=localhost;dbname=test', 'username', 'password');
            // $stmt = $pdo->query("SHOW GLOBAL STATUS");

            // 模拟返回数据
            $result = [
                'buffer_pool' => [
                    'total_mb' => 128.0,
                    'used_mb' => 96.5,
                    'usage_percentage' => 75.39
                ],
                'temp_tables' => [
                    'total_created' => 1000,
                    'disk_created' => 50,
                    'disk_usage_percentage' => 5.0
                ],
                'database_size' => [
                    'total_bytes' => 1073741824, // 1GB
                    'total_mb' => 1024.0,
                    'total_gb' => 1.0
                ]
            ];

            echo "MySQL资源使用情况:\n";
            echo "缓冲池内存:\n";
            echo "- 总内存: " . $result['buffer_pool']['total_mb'] . " MB\n";
            echo "- 已用内存: " . $result['buffer_pool']['used_mb'] . " MB\n";
            echo "- 使用率: " . $result['buffer_pool']['usage_percentage'] . "%\n";

            echo "临时表:\n";
            echo "- 总创建数: " . $result['temp_tables']['total_created'] . "\n";
            echo "- 磁盘表数: " . $result['temp_tables']['disk_created'] . "\n";
            echo "- 磁盘使用率: " . $result['temp_tables']['disk_usage_percentage'] . "%\n";

            echo "数据库大小:\n";
            echo "- 总大小: " . $result['database_size']['total_gb'] . " GB\n";

            return $result;
        } catch (Exception $e) {
            echo "MySQL连接失败: " . $e->getMessage() . "\n";
            return null;
        }
    }
}

// 运行测试
echo "====== Redis & MySQL 资源监控测试 ======\n\n";

$redisMonitor = new SimpleRedisMonitor();
$mysqlMonitor = new SimpleMysqlMonitor();

// 测试Redis
echo "1. Redis监控测试:\n";
echo "==================\n";
$redisUsage = $redisMonitor->getRedisMemoryUsage();
if ($redisUsage !== null) {
    echo "✅ Redis监控成功\n";
} else {
    echo "❌ Redis监控失败\n";
}
echo "\n";

// 测试MySQL
echo "2. MySQL监控测试:\n";
echo "==================\n";
$mysqlUsage = $mysqlMonitor->getMysqlResourceUsage();
if ($mysqlUsage !== null) {
    echo "✅ MySQL监控成功\n";
} else {
    echo "❌ MySQL监控失败\n";
}
echo "\n";

echo "====== 测试完成 ======\n";
echo "注意: 这是模拟数据测试，实际使用时需要配置真实的Redis和MySQL连接\n";
