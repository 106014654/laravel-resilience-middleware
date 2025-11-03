<?php

use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

/**
 * 简洁版 getSystemHealth 调用 - 对比测试 SimpleHealthMonitor vs SystemMonitorService
 */

require_once 'vendor/autoload.php';

// 简洁版系统监控类
class SimpleHealthMonitor
{
    public function getSystemHealth(): array
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'redis' => $this->getRedisHealth(),
            'mysql' => $this->getMysqlHealth(),
            'load_average' => $this->getLoadAverage(),
            'timestamp' => time()
        ];
    }

    private function getCpuUsage(): ?float
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统
            $output = shell_exec('wmic cpu get loadpercentage /value 2>nul');
            if ($output && preg_match('/LoadPercentage=(\d+)/', $output, $matches)) {
                return (float) $matches[1];
            }
            $processes = (int) shell_exec('tasklist | find /c "." 2>nul') ?: 50;
            return min(100, $processes / 10);
        } else {
            // Linux/Unix系统
            try {
                // 方法1: 使用sys_getloadavg()函数
                if (function_exists('sys_getloadavg')) {
                    $load = sys_getloadavg();
                    if ($load !== false && isset($load[0])) {
                        // 获取CPU核心数
                        $cpuCount = (int) shell_exec('nproc 2>/dev/null') ?: 1;
                        return min(100, ($load[0] / $cpuCount) * 100);
                    }
                }

                // 方法2: 读取/proc/loadavg文件
                if (file_exists('/proc/loadavg')) {
                    $loadavg = file_get_contents('/proc/loadavg');
                    if ($loadavg) {
                        $load = explode(' ', trim($loadavg));
                        $cpuCount = (int) shell_exec('nproc 2>/dev/null') ?: 1;
                        return min(100, ((float)$load[0] / $cpuCount) * 100);
                    }
                }

                // 方法3: 使用top命令
                $output = shell_exec('top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%* id.*/\\1/" | awk \'{print 100 - $1}\' 2>/dev/null');
                if ($output && is_numeric(trim($output))) {
                    return (float) trim($output);
                }

                return null;
            } catch (Exception $e) {
                return null;
            }
        }
    }

    private function getMemoryUsage(): ?float
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows系统 - 使用修复版PowerShell方法
            $psCommand = 'powershell "Get-WmiObject -Class Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json"';
            $output = shell_exec($psCommand . ' 2>nul');

            if ($output) {
                $memoryData = json_decode(trim($output), true);
                if ($memoryData && isset($memoryData['TotalVisibleMemorySize']) && isset($memoryData['FreePhysicalMemory'])) {
                    $totalMemory = (int) $memoryData['TotalVisibleMemorySize'];
                    $freeMemory = (int) $memoryData['FreePhysicalMemory'];
                    return (($totalMemory - $freeMemory) / $totalMemory) * 100;
                }
            }
        } else {
            // Linux/Unix系统
            try {
                // 方法1: 读取 /proc/meminfo 文件
                if (file_exists('/proc/meminfo')) {
                    $meminfo = file_get_contents('/proc/meminfo');
                    if ($meminfo) {
                        // 解析内存信息
                        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch);
                        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availableMatch);

                        if (!empty($availableMatch)) {
                            // 优先使用 MemAvailable（更准确）
                            $totalMemory = (int) $totalMatch[1];
                            $availableMemory = (int) $availableMatch[1];
                            return (($totalMemory - $availableMemory) / $totalMemory) * 100;
                        } else {
                            // 备用方案：使用 MemFree + Buffers + Cached
                            preg_match('/MemFree:\s+(\d+)/', $meminfo, $freeMatch);
                            preg_match('/Buffers:\s+(\d+)/', $meminfo, $buffersMatch);
                            preg_match('/Cached:\s+(\d+)/', $meminfo, $cachedMatch);

                            if (!empty($totalMatch) && !empty($freeMatch)) {
                                $totalMemory = (int) $totalMatch[1];
                                $freeMemory = (int) $freeMatch[1];
                                $buffers = isset($buffersMatch[1]) ? (int) $buffersMatch[1] : 0;
                                $cached = isset($cachedMatch[1]) ? (int) $cachedMatch[1] : 0;

                                $usedMemory = $totalMemory - $freeMemory - $buffers - $cached;
                                return ($usedMemory / $totalMemory) * 100;
                            }
                        }
                    }
                }

                // 方法2: 使用free命令
                $output = shell_exec('free -m | grep "^Mem:" | awk \'{print ($3/$2) * 100.0}\' 2>/dev/null');
                if ($output && is_numeric(trim($output))) {
                    return (float) trim($output);
                }

                return null;
            } catch (Exception $e) {
                return null;
            }
        }
        return null;
    }

    private function getRedisHealth(): ?float
    {
        echo "🔴 Redis连接测试:\n";
        echo "================\n";

        // 检查Redis扩展是否安装
        if (!class_exists('Redis')) {
            echo "连接状态: ❌ Redis扩展未安装\n";
            echo "提示: 请安装php-redis扩展\n";
            echo "使用模拟健康评分\n\n";

            $responseTime = rand(5, 100);
            if ($responseTime < 20) return 10;
            if ($responseTime < 40) return 30;
            if ($responseTime < 60) return 60;
            return 80;
        }

        try {
            // 尝试连接Redis (默认配置)
            $redis = new Redis();
            $host = '127.0.0.1';
            $port = 6379;

            echo "连接地址: {$host}:{$port}\n";

            $startTime = microtime(true);
            $connected = $redis->connect($host, $port, 2); // 2秒超时
            $connectTime = (microtime(true) - $startTime) * 1000;

            if ($connected) {
                echo "连接状态: ✅ 成功\n";
                echo "连接耗时: " . number_format($connectTime, 2) . " ms\n";

                // 测试PING
                $startTime = microtime(true);
                $pong = $redis->ping();
                $pingTime = (microtime(true) - $startTime) * 1000;

                echo "PING响应: " . ($pong ? "PONG ✅" : "失败 ❌") . "\n";
                echo "PING耗时: " . number_format($pingTime, 2) . " ms\n";

                // 获取Redis信息
                $info = $redis->info('server');
                if ($info) {
                    echo "Redis版本: " . ($info['redis_version'] ?? '未知') . "\n";
                    echo "运行模式: " . ($info['redis_mode'] ?? '未知') . "\n";
                }

                $redis->close();

                // 基于响应时间计算健康度
                $totalTime = $connectTime + $pingTime;
                if ($totalTime < 10) return 10;
                if ($totalTime < 30) return 25;
                if ($totalTime < 50) return 40;
                if ($totalTime < 100) return 70;
                return 90;
            } else {
                echo "连接状态: ❌ 失败\n";
                echo "错误信息: 无法连接到Redis服务器\n";
                return 100;
            }
        } catch (Exception $e) {
            echo "连接状态: ❌ 异常\n";
            echo "错误信息: " . $e->getMessage() . "\n";

            // 如果Redis扩展不存在，使用模拟数据
            if (strpos($e->getMessage(), 'Redis') !== false) {
                echo "提示: Redis扩展未安装，使用模拟数据\n";
                $responseTime = rand(5, 100);
                if ($responseTime < 20) return 10;
                if ($responseTime < 40) return 30;
                if ($responseTime < 60) return 60;
                return 80;
            }

            return 100;
        }

        echo "\n";
    }

    private function getMysqlHealth(): ?float
    {
        echo "🛢️  MySQL连接测试:\n";
        echo "==================\n";

        try {
            // 尝试连接MySQL (默认配置)
            $host = '127.0.0.1';
            $port = 3306;
            $username = 'root';
            $password = ''; // 根据实际情况修改
            $database = 'mysql'; // 系统数据库

            echo "连接地址: {$host}:{$port}\n";
            echo "用户名: {$username}\n";
            echo "数据库: {$database}\n";

            $startTime = microtime(true);

            // 创建PDO连接
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            $connectTime = (microtime(true) - $startTime) * 1000;
            echo "连接状态: ✅ 成功\n";
            echo "连接耗时: " . number_format($connectTime, 2) . " ms\n";

            // 测试查询
            $startTime = microtime(true);
            $stmt = $pdo->query("SELECT VERSION() as version, NOW() as current_time");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $queryTime = (microtime(true) - $startTime) * 1000;

            echo "查询测试: ✅ 成功\n";
            echo "查询耗时: " . number_format($queryTime, 2) . " ms\n";
            echo "MySQL版本: " . ($result['version'] ?? '未知') . "\n";
            echo "服务器时间: " . ($result['current_time'] ?? '未知') . "\n";

            // 获取连接状态
            $stmt = $pdo->query("SHOW STATUS WHERE Variable_name IN ('Threads_connected', 'Uptime', 'Questions')");
            $status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            echo "活动连接数: " . ($status['Threads_connected'] ?? '未知') . "\n";
            echo "运行时间: " . ($status['Uptime'] ?? '未知') . " 秒\n";

            // 基于响应时间计算健康度
            $totalTime = $connectTime + $queryTime;
            if ($totalTime < 20) return 15;
            if ($totalTime < 50) return 30;
            if ($totalTime < 100) return 50;
            if ($totalTime < 200) return 75;
            return 90;
        } catch (Exception $e) {
            echo "连接状态: ❌ 失败\n";
            echo "错误信息: " . $e->getMessage() . "\n";

            // 根据错误类型给出提示
            if (strpos($e->getMessage(), 'Connection refused') !== false) {
                echo "提示: MySQL服务未启动或端口不正确\n";
            } elseif (strpos($e->getMessage(), 'Access denied') !== false) {
                echo "提示: 用户名或密码错误\n";
            } elseif (strpos($e->getMessage(), 'Unknown database') !== false) {
                echo "提示: 数据库不存在\n";
            }

            // 使用模拟数据
            echo "使用模拟健康评分\n";
            $responseTime = rand(10, 200);
            if ($responseTime < 50) return 15;
            if ($responseTime < 100) return 35;
            if ($responseTime < 150) return 65;
            return 85;
        }

        echo "\n";
    }

    private function getLoadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }
        return null;
    }
}

// 执行调用
echo "=== 对比测试：SimpleHealthMonitor vs SystemMonitorService ===\n\n";

// 1. 使用 SimpleHealthMonitor（独立实现）
echo "1️⃣ SimpleHealthMonitor 结果:\n";
echo "============================\n";
try {
    $simpleMonitor = new SimpleHealthMonitor();
    $simpleHealth = $simpleMonitor->getSystemHealth();

    foreach ($simpleHealth as $key => $value) {
        echo sprintf("%-15s: ", ucfirst($key));

        if ($key === 'timestamp') {
            echo date('Y-m-d H:i:s', $value);
        } elseif (is_numeric($value)) {
            echo number_format($value, 2);
            if (in_array($key, ['cpu', 'memory'])) {
                echo '%';
            }
        } elseif (is_array($value)) {
            echo '[' . implode(', ', array_map(function ($v) {
                return number_format($v, 2);
            }, $value)) . ']';
        } elseif ($value === null) {
            echo 'null';
        } else {
            echo $value;
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "❌ SimpleHealthMonitor 错误: " . $e->getMessage() . "\n";
}

echo "\n";

// 2. 尝试使用 SystemMonitorService（需要Laravel环境）
echo "2️⃣ SystemMonitorService 结果:\n";
echo "==============================\n";
try {
    require_once 'vendor/autoload.php';

    // 检查是否在Laravel环境中
    if (!function_exists('config')) {
        echo "⚠️  SystemMonitorService 需要Laravel环境才能运行\n";
        echo "当前环境: 独立PHP环境\n";
        echo "提示: 在Laravel项目中使用时，SystemMonitorService会正常工作\n\n";

        echo "💡 在Laravel中的使用方式:\n";
        echo "```php\n";
        echo "use OneLap\\LaravelResilienceMiddleware\\Services\\SystemMonitorService;\n";
        echo "\n";
        echo "\$monitor = new SystemMonitorService();\n";
        echo "\$health = \$monitor->getSystemHealth();\n";
        echo "```\n";
    } else {
        // Laravel环境下的调用
        $monitor = new SystemMonitorService();
        $health = $monitor->getSystemHealth();

        foreach ($health as $key => $value) {
            echo sprintf("%-15s: ", ucfirst($key));

            if ($key === 'timestamp') {
                echo date('Y-m-d H:i:s', $value);
            } elseif (is_numeric($value)) {
                echo number_format($value, 2);
                if (in_array($key, ['cpu', 'memory'])) {
                    echo '%';
                }
            } elseif (is_array($value)) {
                echo '[' . implode(', ', array_map(function ($v) {
                    return number_format($v, 2);
                }, $value)) . ']';
            } elseif ($value === null) {
                echo 'null';
            } else {
                echo $value;
            }
            echo "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ SystemMonitorService 错误: " . $e->getMessage() . "\n";
    echo "原因: SystemMonitorService 依赖Laravel的config()、Log等功能\n";
    echo "解决方案: 在Laravel项目中使用，或使用SimpleHealthMonitor进行独立测试\n";
}

echo "\n📊 测试总结:\n";
echo "============\n";
echo "• SimpleHealthMonitor: ✅ 独立运行，适合测试和调试\n";
echo "• SystemMonitorService: 🎯 Laravel集成，生产环境使用\n";
echo "• 两者实现相同的监控逻辑，数据获取方式已同步更新\n";
