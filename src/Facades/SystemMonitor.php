<?php

namespace OneLap\LaravelResilienceMiddleware\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * SystemMonitor Facade
 * 
 * @method static array getSystemMetrics()
 * @method static string getSystemPressureLevel()
 * @method static float getCpuUsage()
 * @method static float getMemoryUsage()
 * @method static array getRedisInfo()
 * @method static array getDatabaseInfo()
 * @method static bool isSystemHealthy()
 * @method static array getDetailedSystemStatus()
 */
class SystemMonitor extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'system.monitor';
    }
}
