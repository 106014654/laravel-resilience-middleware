<?php

namespace OneLap\LaravelResilienceMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

/**
 * 服务降级中间件（简化版）
 * 降级时使用 putSimpleFlag 值为 1，恢复时使用 forgetDegradationState 直接删除
 */
class ServiceDegradationMiddleware
{
    protected $systemMonitor;
    protected $config;
    protected $redis;
    protected $currentRequest;

    // 缓存键前缀，确保中间件缓存键的唯一性和跨请求一致性
    protected const CACHE_PREFIX = 'resilience:';

    public function __construct(SystemMonitorService $systemMonitor)
    {
        $this->systemMonitor = $systemMonitor;
        $this->config = config('resilience.service_degradation', []);
        $config = config('resilience.system_monitor', []);

        // 初始化Redis连接
        if (isset($config['redis']['connection'])) {
            $this->redis = Redis::connection($config['redis']['connection']);
        } else {
            $this->redis = Redis::connection('default');
        }
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, $mode = 'auto')
    {
        $this->currentRequest = $request;

        // 检查是否启用服务降级
        $config = config('resilience.service_degradation', []);
        if (!($config['enabled'] ?? true)) {
            return $next($request);
        }

        // 获取当前系统资源状态
        $resourceStatus = $this->systemMonitor->getResourceStatus();

        // 计算当前每个资源应有的降级级别
        $currentResourceLevels = $this->calculateResourceDegradationLevels($resourceStatus);

        // 从Redis获取之前的各资源降级级别
        $previousResourceLevels = $this->getDegradationState('resource_degradation_levels', []);

        // 检查是否需要恢复
        $needsRecovery = $this->checkResourcesNeedRecovery($previousResourceLevels, $currentResourceLevels);
        if ($needsRecovery) {
            $this->executeRecovery($previousResourceLevels, $currentResourceLevels, $resourceStatus);
            return $next($request);
        }

        // 检查是否需要降级
        $needsDegradation = $this->checkResourcesNeedDegradation($previousResourceLevels, $currentResourceLevels);
        if ($needsDegradation) {
            $this->executeDegradation($previousResourceLevels, $currentResourceLevels, $resourceStatus, $request);

            $maxCurrentLevel = max(array_values($currentResourceLevels));
            if ($mode === 'block' || ($mode === 'auto' && $maxCurrentLevel >= 3)) {
                return $this->createDegradedResponse($maxCurrentLevel);
            }
        }

        return $next($request);
    }

    /**
     * 计算每个资源的降级级别
     */
    protected function calculateResourceDegradationLevels(array $resourceStatus): array
    {
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);
        $resourceLevels = [];

        foreach ($resourceStatus as $resource => $usage) {
            $resourceLevels[$resource] = 0;
            if (!isset($thresholds[$resource])) {
                continue;
            }

            foreach ($thresholds[$resource] as $threshold => $level) {
                if ($usage >= $threshold) {
                    $resourceLevels[$resource] = $level;
                }
            }
        }

        return $resourceLevels;
    }

    /**
     * 检查是否有资源需要恢复
     */
    protected function checkResourcesNeedRecovery(array $previousLevels, array $currentLevels): bool
    {
        foreach ($previousLevels as $resource => $previousLevel) {
            $currentLevel = $currentLevels[$resource] ?? 0;
            if ($previousLevel > 0 && $currentLevel < $previousLevel) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否有资源需要降级
     */
    protected function checkResourcesNeedDegradation(array $previousLevels, array $currentLevels): bool
    {
        foreach ($currentLevels as $resource => $currentLevel) {
            $previousLevel = $previousLevels[$resource] ?? 0;
            if ($currentLevel > $previousLevel) {
                return true;
            }
        }
        return false;
    }

    /**
     * 执行降级
     */
    protected function executeDegradation(array $previousLevels, array $currentLevels, array $resourceStatus, Request $request): void
    {
        $strategies = config('resilience.service_degradation.strategies', []);

        foreach ($currentLevels as $resource => $currentLevel) {
            $previousLevel = $previousLevels[$resource] ?? 0;

            if ($currentLevel > $previousLevel) {
                // 执行该资源的降级动作：逐级从previousLevel+1到currentLevel
                // 例如：从级别1升级到级别3，执行级别2和3的动作
                for ($level = $previousLevel + 1; $level <= $currentLevel; $level++) {
                    if (isset($strategies[$resource])) {
                        foreach ($strategies[$resource] as $threshold => $strategy) {
                            if (($strategy['level'] ?? 0) === $level) {
                                $this->executeResourceStrategy($resource, $strategy, $request);
                            }
                        }
                    }
                }
            }
        }

        // 保存资源级别状态到Redis
        $this->putDegradationState('resource_degradation_levels', $currentLevels);
        $this->putDegradationState('current_degradation_level', max(array_values($currentLevels)));
    }

    /**
     * 执行恢复
     */
    protected function executeRecovery(array $previousLevels, array $currentLevels, array $resourceStatus): void
    {
        $recovered = false;

        foreach ($previousLevels as $resource => $previousLevel) {
            $currentLevel = $currentLevels[$resource] ?? 0;
            if ($previousLevel > $currentLevel) {
                // 执行该资源的恢复：只恢复从currentLevel+1到previousLevel之间的级别
                // 例如：从级别3恢复到级别1，只恢复级别2和3的动作，保留级别1的动作
                $this->executeResourceRecovery($resource, $previousLevel, $currentLevel);
                $recovered = true;
            }
        }

        if ($recovered) {
            // 更新资源级别状态
            $this->putDegradationState('resource_degradation_levels', $currentLevels);
            $maxLevel = max(array_merge([0], array_values($currentLevels)));

            if ($maxLevel === 0) {
                $this->performCompleteRecovery();
            } else {
                $this->putDegradationState('current_degradation_level', $maxLevel);
            }
        }
    }

    /**
     * 执行特定资源的降级策略
     */
    protected function executeResourceStrategy(string $resource, array $strategy, Request $request): void
    {
        if (isset($strategy['actions'])) {
            foreach ($strategy['actions'] as $action) {
                $this->executeAction($action, $request);
            }
        }
    }

    /**
     * 执行特定资源的恢复 - 只恢复指定级别范围的动作
     */
    protected function executeResourceRecovery(string $resource, int $fromLevel, int $toLevel): void
    {
        $strategies = config('resilience.service_degradation.strategies', []);

        if (isset($strategies[$resource])) {
            foreach ($strategies[$resource] as $threshold => $strategy) {
                $strategyLevel = $strategy['level'] ?? 0;
                // 只恢复从toLevel+1到fromLevel之间的级别
                // 例如：从级别3(fromLevel)恢复到级别1(toLevel)，只删除级别2和3的动作标识
                if ($strategyLevel > $toLevel && $strategyLevel <= $fromLevel) {
                    // 恢复该级别的actions：直接删除降级标识
                    if (isset($strategy['actions'])) {
                        foreach ($strategy['actions'] as $action) {
                            $this->forgetDegradationState($action);
                        }
                    }
                }
            }
        }
    }

    /**
     * 执行降级动作
     */
    protected function executeAction(string $action, Request $request): void
    {
        switch ($action) {
            // CPU相关动作
            case 'disable_heavy_analytics':
            case 'reduce_log_verbosity':
            case 'disable_background_jobs':
            case 'enable_aggressive_caching':
            case 'disable_recommendations_engine':
            case 'disable_realtime_features':
            case 'enable_minimal_response_processing':
            case 'gc_forced':
                if ($action === 'gc_forced') gc_collect_cycles();
                $this->putSimpleFlag($action);
                break;
            case 'enable_emergency_cpu_mode':
            case 'enable_static_responses_only':
                $this->putSimpleFlag($action);
                break;

            // Memory相关动作  
            case 'reduce_cache_size':
                $this->putSimpleFlag($action);
                $this->flushResilienceCache();
                break;
            case 'disable_file_processing':
            case 'enable_minimal_object_creation':
            case 'enable_large_request_rejection':
                $this->putSimpleFlag($action);
                break;
            case 'perform_emergency_memory_cleanup':
                $this->flushResilienceCache();
                gc_collect_cycles();
                $this->putSimpleFlag($action);
                break;

            // Redis相关动作
            case 'reduce_redis_operations':
            case 'enable_local_cache_fallback':
            case 'optimize_redis_queries':
            case 'bypass_non_critical_redis':
            case 'enable_redis_read_only_mode':
            case 'disable_redis_writes':
            case 'enable_complete_redis_bypass':
                $this->putSimpleFlag($action);
                break;

            // Database相关动作
            case 'enable_query_optimization':
            case 'prioritize_read_replicas':
            case 'enable_frequent_query_caching':
            case 'enable_database_read_only_mode':
            case 'disable_complex_queries':
            case 'force_query_caching':
            case 'enable_database_emergency_mode':
            case 'enable_cache_only_responses':
            case 'enable_minimal_database_access':
            case 'enable_complete_database_bypass':
                $this->putSimpleFlag($action);
                break;

            default:
                Log::debug("Unknown degradation action: {$action}");
                break;
        }
    }

    /**
     * 执行完整恢复
     */
    protected function performCompleteRecovery(): void
    {
        // 删除所有降级标识
        $forgetKeys = [
            'current_degradation_level',
            'current_degradation_state',
            'resource_degradation_levels',
            'heavy_analytics_disabled',
            'log_verbosity_reduced',
            'background_jobs_disabled',
            'cache_aggressive',
            'recommendations_disabled',
            'realtime_disabled',
            'response_minimal',
            'gc_forced',
            'emergency_cpu_mode',
            'response_static_only',
            'cache_size_reduction',
            'file_processing_disabled',
            'minimal_object_creation',
            'emergency_memory_cleanup_performed',
            'request_too_large',
            'redis_operations_reduced',
            'cache_local_fallback',
            'redis_query_optimized',
            'redis_bypass_keys',
            'redis_read_only',
            'redis_writes_disabled',
            'redis_bypassed',
            'database_query_cache',
            'database_read_preference',
            'database_query_cache_enabled',
            'database_read_only',
            'complex_queries_disabled',
            'database_force_cache',
            'database_emergency_mode',
            'response_cache_only',
            'database_minimal_access',
            'database_bypassed'
        ];

        foreach ($forgetKeys as $key) {
            $this->forgetDegradationState($key);
        }
    }

    /**
     * 创建降级响应
     */
    protected function createDegradedResponse(int $level)
    {
        $response = response()->json([
            'message' => 'Service temporarily degraded',
            'level' => $level,
            'degraded' => true
        ], 503);

        return $response;
    }

    /**
     * 设置降级标识 - 默认值为1
     */
    protected function putSimpleFlag(string $key, $value = 1, $ttl = 600): bool
    {
        return $this->putDegradationState($key, $value, $ttl);
    }

    /**
     * 获取标识值
     */
    protected function getSimpleFlag(string $key, $default = null)
    {
        return $this->getDegradationState($key, $default);
    }

    /**
     * 统一存储降级状态到 Redis
     */
    protected function putDegradationState(string $key, $value, $ttl = 600): bool
    {
        $redisKey = self::CACHE_PREFIX . $this->getKeyWithIpPort($key);

        // 如果值是数组，序列化为JSON存储
        if (is_array($value)) {
            $value = json_encode($value);
        }

        if ($ttl) {
            return (bool)$this->redis->set($redisKey, $value, 'EX', $this->convertTtlToSeconds($ttl));
        } else {
            return (bool)$this->redis->set($redisKey, $value);
        }
    }

    /**
     * 统一获取降级状态
     */
    protected function getDegradationState(string $key, $default = null)
    {
        $redisKey = self::CACHE_PREFIX . $this->getKeyWithIpPort($key);
        $val = $this->redis->get($redisKey);

        if ($val === null) {
            return $default;
        }

        // 尝试JSON解码，如果失败则返回原始值
        $decoded = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $val;
    }

    /**
     * 统一删除降级状态
     */
    protected function forgetDegradationState(string $key): void
    {
        $redisKey = self::CACHE_PREFIX . $this->getKeyWithIpPort($key);
        $this->redis->del($redisKey);
    }

    /**
     * 构建带IP端口的key
     */
    protected function getKeyWithIpPort(string $key): string
    {
        $ip = getHostByName(getHostName());
        if (!empty($_SERVER['SERVER_ADDR'])) {
            $ip = $_SERVER['SERVER_ADDR'];
        }
        $port = $_SERVER['SERVER_PORT'] ?? '80';
        return $ip . ':' . $port . ':' . $key;
    }

    /**
     * TTL 转换为秒数
     */
    protected function convertTtlToSeconds($ttl): int
    {
        if (is_int($ttl)) return $ttl;
        if ($ttl instanceof \DateInterval) return (new \DateTime())->add($ttl)->getTimestamp() - time();
        if (method_exists($ttl, 'diffInSeconds')) return $ttl->diffInSeconds();
        if (method_exists($ttl, 'getTimestamp')) return $ttl->getTimestamp() - time();
        return 600;
    }

    /**
     * 清理弹性缓存
     */
    protected function flushResilienceCache(): bool
    {
        try {
            $keyPattern = self::CACHE_PREFIX . '*';
            $keys = $this->redis->keys($keyPattern);
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to flush resilience cache", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
