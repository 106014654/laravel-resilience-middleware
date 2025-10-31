<?php

namespace OneLap\LaravelResilienceMiddleware\Tests;

use PHPUnit\Framework\TestCase;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

class SystemMonitorServiceTest extends TestCase
{
    protected $systemMonitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->systemMonitor = new SystemMonitorService();
    }

    public function testGetCpuUsage()
    {
        $cpuUsage = $this->systemMonitor->getCpuUsage();

        $this->assertIsFloat($cpuUsage);
        $this->assertGreaterThanOrEqual(0, $cpuUsage);
        $this->assertLessThanOrEqual(100, $cpuUsage);
    }

    public function testGetMemoryUsage()
    {
        $memoryUsage = $this->systemMonitor->getMemoryUsage();

        $this->assertIsFloat($memoryUsage);
        $this->assertGreaterThanOrEqual(0, $memoryUsage);
        $this->assertLessThanOrEqual(100, $memoryUsage);
    }

    public function testGetSystemPressureLevel()
    {
        $pressureLevel = $this->systemMonitor->getSystemPressureLevel();

        $this->assertIsString($pressureLevel);
        $this->assertContains($pressureLevel, ['low', 'medium', 'high', 'critical']);
    }

    public function testDeterminePressureLevel()
    {
        // 测试低压力
        $this->assertEquals('low', $this->systemMonitor->determinePressureLevel(50, 50));

        // 测试中等压力
        $this->assertEquals('medium', $this->systemMonitor->determinePressureLevel(75, 50));

        // 测试高压力
        $this->assertEquals('high', $this->systemMonitor->determinePressureLevel(90, 75));

        // 测试临界压力
        $this->assertEquals('critical', $this->systemMonitor->determinePressureLevel(98, 90));
    }
}
