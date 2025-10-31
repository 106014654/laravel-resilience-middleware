<?php

namespace OneLap\LaravelResilienceMiddleware\Tests;

use PHPUnit\Framework\TestCase;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

class SimpleSystemMonitorTest extends TestCase
{
    protected $systemMonitor;

    protected function setUp(): void
    {
        parent::setUp();

        // 模拟 config 函数
        if (!function_exists('config')) {
            function config($key = null, $default = null)
            {
                $configs = [
                    'resilience.system_monitor' => [
                        'pressure_weights' => [
                            'cpu' => 0.4,
                            'memory' => 0.3,
                            'redis' => 0.2,
                            'mysql' => 0.1,
                        ],
                        'cpu' => [
                            'thresholds' => [
                                'medium' => 70,
                                'high' => 85,
                                'critical' => 95,
                            ]
                        ]
                    ]
                ];

                if ($key === null) {
                    return $configs;
                }

                $keys = explode('.', $key);
                $value = $configs;

                foreach ($keys as $k) {
                    if (isset($value[$k])) {
                        $value = $value[$k];
                    } else {
                        return $default;
                    }
                }

                return $value;
            }
        }

        $this->systemMonitor = new SystemMonitorService();
    }

    public function testSystemMonitorServiceInstantiation()
    {
        $this->assertInstanceOf(SystemMonitorService::class, $this->systemMonitor);
    }

    public function testGetSystemHealthReturnsArray()
    {
        $reflection = new \ReflectionClass($this->systemMonitor);
        $method = $reflection->getMethod('getSystemHealth');
        $method->setAccessible(true);

        $health = $method->invoke($this->systemMonitor);

        $this->assertIsArray($health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertIsInt($health['timestamp']);
    }

    public function testGetSystemPressureLevelReturnsString()
    {
        $pressureLevel = $this->systemMonitor->getSystemPressureLevel();

        $this->assertIsString($pressureLevel);
        $this->assertContains($pressureLevel, ['low', 'medium', 'high', 'critical']);
    }

    public function testCpuUsageIsValid()
    {
        $reflection = new \ReflectionClass($this->systemMonitor);
        $method = $reflection->getMethod('getCpuUsage');
        $method->setAccessible(true);

        $cpuUsage = $method->invoke($this->systemMonitor);

        if ($cpuUsage !== null) {
            $this->assertIsFloat($cpuUsage);
            $this->assertGreaterThanOrEqual(0, $cpuUsage);
            $this->assertLessThanOrEqual(100, $cpuUsage);
        } else {
            // CPU 使用率获取可能在某些环境中失败，这是正常的
            $this->assertNull($cpuUsage);
        }
    }

    public function testMemoryUsageIsValid()
    {
        $reflection = new \ReflectionClass($this->systemMonitor);
        $method = $reflection->getMethod('getMemoryUsage');
        $method->setAccessible(true);

        $memoryUsage = $method->invoke($this->systemMonitor);

        if ($memoryUsage !== null) {
            $this->assertIsFloat($memoryUsage);
            $this->assertGreaterThanOrEqual(0, $memoryUsage);
            $this->assertLessThanOrEqual(100, $memoryUsage);
        } else {
            // 内存使用率获取可能在某些环境中失败，这是正常的
            $this->assertNull($memoryUsage);
        }
    }
}
