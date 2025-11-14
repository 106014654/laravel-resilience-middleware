<?php

namespace OneLap\LaravelResilienceMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

/**
 * 服务降级中间件
 * 根据系统压力自动降级服务
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

        // 加载服务降级配置（包含监控和日志配置）
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
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null $mode 处理模式 (auto|block|passthrough)
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $mode = 'auto')
    {
        // 保存当前请求对象以供缓存键构建使用
        $this->currentRequest = $request;

        // 获取配置
        $config = config('resilience.service_degradation', []);
        // 检查是否启用服务降级
        if (!($config['enabled'] ?? true)) {
            return $next($request);
        }

        // 获取当前系统资源状态
        $resourceStatus = $this->systemMonitor->getResourceStatus();

        // 记录资源监控数据（如果启用）
        $this->logResourceMonitoring($resourceStatus, $config);

        // 计算当前每个资源应有的降级级别（基于系统资源评估）
        $currentResourceLevels = $this->calculateResourceDegradationLevels($resourceStatus);

        // 从Redis获取之前的各资源降级级别
        $previousResourceLevels = $this->getDegradationState('resource_degradation_levels', []);

        // 获取恢复配置
        $recoveryConfig = config('resilience.service_degradation.recovery', []);
        $gradualRecovery = $recoveryConfig['gradual_recovery'] ?? true;

        // 检查每个资源是否需要恢复
        $needsRecovery = $this->checkResourcesNeedRecovery($previousResourceLevels, $currentResourceLevels);
        if ($needsRecovery) {
            if ($gradualRecovery) {
                // 执行按资源的逐级恢复
                $recoveryExecuted = $this->executeResourceBasedGradualRecovery($previousResourceLevels, $currentResourceLevels, $resourceStatus);
                if ($recoveryExecuted) {
                    // 恢复成功，直接执行下一个中间件
                    return $next($request);
                }
            } else {
                // 彻底清理所有降级相关资源key
                $this->flushResilienceCache();
                // 直接执行下一个中间件
                return $next($request);
            }
        }

        // 检查每个资源是否需要降级
        $needsDegradation = $this->checkResourcesNeedDegradation($previousResourceLevels, $currentResourceLevels);
        if ($needsDegradation) {
            // 执行按资源的逐级降级：从之前级别到当前级别的所有操作
            $this->executeResourceBasedGradualDegradation($previousResourceLevels, $currentResourceLevels, $resourceStatus, $request);

            // 获取最大降级级别用于响应决策
            $maxCurrentLevel = max(array_values($currentResourceLevels));

            // 记录降级事件（如果启用）
            $this->logDegradationEvent($request, $maxCurrentLevel, $resourceStatus, $mode);

            // 根据模式和降级级别决定处理方式
            if ($mode === 'block' || ($mode === 'auto' && $maxCurrentLevel >= 3)) {
                // 阻塞模式或高级别降级：直接返回降级响应
                return $this->createDegradedResponse($maxCurrentLevel);
            } else {
                // 透传模式：设置降级标识，继续执行后续逻辑
                $this->setDegradationContext($request, $maxCurrentLevel, $resourceStatus);
            }
        }

        return $next($request);
    }


    /**
     * 处理阻塞模式的服务降级
     *
     * @param Request $request
     * @param string $systemPressure
     * @param array $config
     * @return mixed
     */
    protected function handleBlockingDegradation(Request $request, $systemPressure, array $config)
    {
        $strategy = $config['strategy'];

        switch ($strategy) {
            case 'cache_fallback':
                return $this->handleCacheFallback($request, $config);

            case 'simplified_response':
                return $this->handleSimplifiedResponse($request, $config);

            case 'error_response':
                return $this->handleErrorResponse($request, $config);

            case 'redirect':
                return $this->handleRedirect($request, $config);

            case 'queue_delay':
                return $this->handleQueueDelay($request, $config);

            default:
                return $this->handleDefaultDegradation($request, $config);
        }
    }

    /**
     * 缓存降级策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleCacheFallback(Request $request, array $config)
    {
        $cacheKey = $this->generateCacheKey($request);
        $cachedResponse = $this->getSimpleFlag($cacheKey);

        if ($cachedResponse) {
            Log::info('Serving cached response due to degradation', [
                'cache_key' => $cacheKey,
                'route' => $request->route() ? $request->route()->getName() : null
            ]);

            if ($request->expectsJson()) {
                return response()->json($cachedResponse, 200, ['X-Cache' => 'HIT-DEGRADED']);
            }

            return response($cachedResponse, 200, ['X-Cache' => 'HIT-DEGRADED']);
        }

        // 如果没有缓存，返回简化响应
        return $this->handleSimplifiedResponse($request, $config);
    }

    /**
     * 简化响应策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleSimplifiedResponse(Request $request, array $config)
    {
        if ($request->expectsJson()) {
            $response = [
                'message' => 'Service is running in degraded mode',
                'data' => $this->getSimplifiedData($request, $config),
                'degraded' => true
            ];

            return response()->json($response, 200, ['X-Degraded' => 'true']);
        }

        // 对于非 JSON 请求，返回简化的 HTML 或文本
        return response('Service temporarily simplified due to high load', 200, [
            'X-Degraded' => 'true'
        ]);
    }

    /**
     * 错误响应策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleErrorResponse(Request $request, array $config)
    {
        $statusCode = isset($config['error_status_code']) ? $config['error_status_code'] : 503;
        $message = isset($config['error_message']) ? $config['error_message'] : 'Service temporarily unavailable';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'error' => 'service_degraded',
                'retry_after' => 60
            ], $statusCode);
        }

        return response($message, $statusCode);
    }

    /**
     * 重定向策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleRedirect(Request $request, array $config)
    {
        $redirectUrl = isset($config['redirect_url']) ? $config['redirect_url'] : '/maintenance';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Service degraded, please try alternative endpoint',
                'redirect_url' => $redirectUrl
            ], 302);
        }

        return redirect($redirectUrl);
    }

    /**
     * 队列延迟策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleQueueDelay(Request $request, array $config)
    {
        $delay = isset($config['queue_delay']) ? $config['queue_delay'] : 5;

        // 模拟处理延迟
        sleep($delay);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Request processed with delay due to high load',
                'delayed' => true,
                'delay_seconds' => $delay
            ], 200, ['X-Delayed' => $delay]);
        }

        return response('Request processed', 200, [
            'X-Delayed' => $delay
        ]);
    }

    /**
     * 默认降级策略
     *
     * @param Request $request
     * @param array $config
     * @return mixed
     */
    protected function handleDefaultDegradation(Request $request, array $config)
    {
        // 默认使用简化响应
        return $this->handleSimplifiedResponse($request, $config);
    }

    /**
     * 生成缓存键
     *
     * @param Request $request
     * @return string
     */
    protected function generateCacheKey(Request $request)
    {
        $route = $request->route();
        $routeName = $route ? $route->getName() : $request->getPathInfo();
        $queryParams = $request->query();

        // 只包含关键的查询参数
        $keyParams = [];
        $importantParams = ['id', 'page', 'limit', 'type'];

        foreach ($importantParams as $param) {
            if (isset($queryParams[$param])) {
                $keyParams[$param] = $queryParams[$param];
            }
        }

        return 'degradation:' . md5($routeName . serialize($keyParams));
    }

    /**
     * 获取简化的数据
     *
     * @param Request $request
     * @param array $config
     * @return array
     */
    protected function getSimplifiedData(Request $request, array $config)
    {
        // 根据路由返回不同的简化数据
        $route = $request->route();
        $routeName = $route ? $route->getName() : null;

        // 可以根据具体路由定制简化数据
        switch ($routeName) {
            case 'api.user.profile':
                return ['message' => 'Profile data simplified'];

            case 'api.dashboard':
                return ['stats' => ['message' => 'Statistics temporarily unavailable']];

            default:
                return [
                    'message' => 'Data temporarily simplified',
                    'available' => false
                ];
        }
    }



    // ========== Actions 和 Performance Optimizations 实现方法 ==========

    /**
     * 执行配置中定义的actions
     *
     * @param array $actions
     * @param Request $request
     * @return void
     */
    protected function executeActions(array $actions, Request $request): void
    {
        foreach ($actions as $action) {
            switch ($action) {
                // 只保留与 redis 实际存储字段一致的 action
                case 'heavy_analytics_disabled':
                    $this->disableHeavyAnalytics();
                    break;
                case 'log_verbosity_reduced':
                    $this->reduceLogVerbosity();
                    break;
                case 'background_jobs_disabled':
                    $this->disableBackgroundJobs();
                    break;
                case 'cache_aggressive':
                    $this->enableAggressiveCaching();
                    break;
                case 'recommendations_disabled':
                    $this->disableRecommendationsEngine();
                    break;
                case 'realtime_disabled':
                    $this->disableRealTimeFeatures();
                    break;
                case 'response_minimal':
                    $this->enableMinimalResponseProcessing();
                    break;
                case 'gc_forced':
                    $this->forceGarbageCollection();
                    break;
                case 'emergency_cpu_mode':
                    $this->enableEmergencyCpuMode();
                    break;
                case 'request_non_essential':
                    $this->enableNonEssentialRequestRejection($request);
                    break;
                case 'response_static_only':
                    $this->enableStaticResponsesOnly();
                    break;

                // Memory 相关actions
                case 'file_processing_disabled':
                    $this->disableFileProcessing();
                    break;
                case 'minimal_object_creation':
                    $this->enableMinimalObjectCreation();
                    break;
                case 'emergency_memory_cleanup_performed':
                    $this->performEmergencyMemoryCleanup();
                    break;
                case 'request_too_large':
                    $this->enableLargeRequestRejection($request);
                    break;

                // Redis 相关actions
                case 'redis_operations_reduced':
                    $this->reduceRedisOperations();
                    break;
                case 'cache_local_fallback':
                    $this->enableLocalCacheFallback();
                    break;
                case 'redis_query_optimized':
                    $this->optimizeRedisQueries();
                    break;
                case 'redis_bypass_keys':
                    $this->bypassNonCriticalRedis();
                    break;
                case 'cache_priority_order':
                    $this->enableDatabaseCachePriority();
                    break;
                case 'database_redis_pool_size':
                    $this->limitRedisConnections();
                    break;
                case 'redis_read_only':
                    $this->enableRedisReadOnlyMode();
                    break;
                case 'cache_default':
                    $this->enableCompleteDatabaseFallback();
                    break;
                case 'redis_writes_disabled':
                    $this->disableRedisWrites();
                    break;
                case 'redis_bypassed':
                    $this->enableCompleteRedisBypass();
                    break;

                // Database 相关actions
                case 'database_query_cache':
                    $this->enableQueryOptimization();
                    break;
                case 'database_read_preference':
                    $this->prioritizeReadReplicas();
                    break;
                case 'database_query_cache_enabled':
                    $this->enableFrequentQueryCaching();
                    break;
                case 'database_read_only':
                    $this->enableDatabaseReadOnlyMode();
                    break;
                case 'database_complex_queries_disabled':
                    $this->disableComplexQueries();
                    break;
                case 'database_force_cache':
                    $this->forceQueryCaching();
                    break;
                case 'database_emergency_mode':
                    $this->enableDatabaseEmergencyMode();
                    break;
                case 'response_cache_only':
                    $this->enableCacheOnlyResponses();
                    break;
                case 'database_minimal_access':
                    $this->enableMinimalDatabaseAccess();
                    break;
                case 'database_bypassed':
                    $this->enableCompleteDatabaseBypass();
                    break;

                default:
                    Log::warning("Unknown degradation action: {$action}");
                    break;
            }
        }
    }

    /**
     * 应用性能优化配置
     *
     * @param array $optimizations
     * @return void
     */
    protected function applyPerformanceOptimizations(array $optimizations): void
    {
        foreach ($optimizations as $key => $value) {
            switch ($key) {
                case 'gc_frequency':
                    $this->adjustGarbageCollection($value);
                    break;
                case 'cache_strategy':
                    $this->adjustCacheStrategy($value);
                    break;
                case 'query_timeout':
                    $this->adjustQueryTimeout($value);
                    break;
                case 'processing':
                    $this->adjustProcessingMode($value);
                    break;
            }
        }
    }

    // ========== CPU 相关动作实现 ==========

    protected function disableHeavyAnalytics(): void
    {
        // 完全使用 Redis 存储降级状态，确保跨请求可访问
        $this->putSimpleFlag('heavy_analytics_disabled', true, now()->addMinutes(10));

        $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;
        if ($enableDetailedLogging) {
            Log::info('Heavy analytics disabled due to system pressure');
        }
    }

    protected function reduceLogVerbosity(): void
    {
        // 使用 Redis 存储日志级别配置
        $this->putSimpleFlag('log_verbosity_reduced', true, now()->addMinutes(10));
    }

    protected function disableBackgroundJobs(): void
    {
        // 使用 Redis 存储后台作业状态
        $this->putSimpleFlag('background_jobs_disabled', true, now()->addMinutes(10));
    }

    protected function enableAggressiveCaching(): void
    {
        // 使用 Redis 存储缓存配置
        $this->putSimpleFlag('cache_aggressive', true, now()->addMinutes(10));
    }

    protected function disableRecommendationsEngine(): void
    {
        $this->putSimpleFlag('recommendations_disabled', true, now()->addMinutes(10));
    }

    protected function disableRealTimeFeatures(): void
    {
        // 使用 Redis 存储实时功能配置
        $this->putSimpleFlag('realtime_disabled', true, now()->addMinutes(10));
        $this->putSimpleFlag('websockets_enabled', false, now()->addMinutes(10));
        $this->putSimpleFlag('broadcasting_enabled', false, now()->addMinutes(10));
        $this->putSimpleFlag('realtime_features', false, now()->addMinutes(10));
    }

    protected function enableMinimalResponseProcessing(): void
    {
        $this->putSimpleFlag('response_minimal', true, now()->addMinutes(10));
        $this->putSimpleFlag('response_format', 'minimal', now()->addMinutes(10));
    }

    protected function forceGarbageCollection(): void
    {
        gc_collect_cycles();
        $this->putSimpleFlag('gc_forced', now()->timestamp, now()->addMinute());
    }

    protected function enableEmergencyCpuMode(): void
    {
        // 使用 Redis 存储紧急CPU模式配置
        $this->putSimpleFlag('emergency_cpu_mode', true, now()->addMinutes(10));
        $this->putSimpleFlag('queue_sync', false, now()->addMinutes(10));
        $this->putSimpleFlag('session_driver', 'array', now()->addMinutes(10));
    }

    protected function enableNonEssentialRequestRejection(Request $request): void
    {
        // 从配置文件中获取非必要路径列表
        $nonEssentialPaths = config('resilience.service_degradation.non_essential_paths', []);

        $currentPath = $request->path();

        foreach ($nonEssentialPaths as $path) {
            // 兼容低版本 PHP，使用 substr 替代 str_starts_with
            if (substr($currentPath, 0, strlen($path)) === $path) {
                // 命中非必要路径，直接返回降级响应
                Log::info('Non-essential request intercepted and degraded', [
                    'path' => $currentPath,
                    'matched_pattern' => $path,
                    'method' => $request->method(),
                    'ip' => $request->ip()
                ]);

                // 直接返回降级响应
                if ($request->expectsJson()) {
                    response()->json([
                        'message' => 'Service is running in degraded mode (non-essential request)',
                        'degraded' => true
                    ], 200, ['X-Degraded' => 'true'])->send();
                } else {
                    response('Service temporarily simplified due to high load (non-essential request)', 200, [
                        'X-Degraded' => 'true'
                    ])->send();
                }
                exit;
            }
        }
    }

    protected function enableStaticResponsesOnly(): void
    {
        // 使用 Redis 存储静态响应配置
        $this->putSimpleFlag('response_static_only', true, now()->addMinutes(10));
        $this->putSimpleFlag('cache_strategy', 'static_files', now()->addMinutes(10));
    }

    // ========== Memory 相关动作实现 ==========

    protected function reduceCacheSize(float $percentage): void
    {
        $this->putSimpleFlag('cache_size_reduction', $percentage, now()->addMinutes(10));

        // 清理指定百分比的缓存
        $tagsToReduce = ['temp', 'analytics', 'reports'];
        foreach ($tagsToReduce as $tag) {
            if (rand(1, 100) <= $percentage * 100) {
                $this->flushCacheWithTags([$tag]);
            }
        }

        Log::info("Cache size reduced by {$percentage}%");
    }

    protected function disableFileProcessing(): void
    {
        // 使用 Redis 存储文件处理配置
        $this->putSimpleFlag('file_processing_disabled', true, now()->addMinutes(10));
        $this->putSimpleFlag('filesystems_uploads_enabled', false, now()->addMinutes(10));
        $this->putSimpleFlag('image_processing', false, now()->addMinutes(10));
        $this->putSimpleFlag('file_processing', false, now()->addMinutes(10));
    }

    protected function enableMinimalObjectCreation(): void
    {
        // 使用 Redis 存储对象创建配置
        $this->putSimpleFlag('minimal_object_creation', true, now()->addMinutes(10));
        $this->putSimpleFlag('object_pooling_enabled', true, now()->addMinutes(10));
        $this->putSimpleFlag('minimal_objects', true, now()->addMinutes(10));
    }

    protected function performEmergencyMemoryCleanup(): void
    {
        // 清理各种缓存
        $this->flushResilienceCache();

        // 强制垃圾回收
        gc_collect_cycles();

        // 清理视图缓存
        try {
            if (function_exists('view')) {
                // 尝试清理视图缓存
                \Illuminate\Support\Facades\View::flushStateIfAble();
            }
        } catch (\Exception $e) {
            // 忽略视图缓存清理错误
        }

        // 记录清理状态到 Redis
        $this->putSimpleFlag('emergency_memory_cleanup_performed', true, now()->addMinutes(10));

        Log::warning('Emergency memory cleanup performed');
    }

    protected function clearNonCriticalCache(): void
    {
        $nonCriticalTags = ['analytics', 'recommendations', 'reports', 'temp', 'widgets'];

        foreach ($nonCriticalTags as $tag) {
            $this->flushCacheWithTags([$tag]);
        }

        Log::info('Non-critical cache cleared');
    }

    protected function enableLargeRequestRejection(Request $request): void
    {
        $maxSize = 1024 * 1024; // 1MB
        $contentLength = $request->header('content-length', 0);

        if ($contentLength > $maxSize) {
            // 使用 Redis 存储大请求标记
            $this->putSimpleFlag('request_too_large', true, now()->addMinutes(10));
            $this->putSimpleFlag('request_size_limit', $maxSize, now()->addMinutes(10));
        }
    }

    // ========== Redis 相关动作实现 ==========

    protected function reduceRedisOperations(): void
    {
        // 使用 Redis 存储操作限制配置
        $this->putSimpleFlag('redis_operations_reduced', true, now()->addMinutes(10));
        $this->putSimpleFlag('cache_redis_operations_limit', 100, now()->addMinutes(10));

        Log::info('Redis operations reduced');
    }

    protected function enableLocalCacheFallback(): void
    {
        // 使用 Redis 存储本地缓存回退配置
        $this->putSimpleFlag('cache_local_fallback', true, now()->addMinutes(10));
        $this->putSimpleFlag('cache_fallback', 'array', now()->addMinutes(10));
    }

    protected function optimizeRedisQueries(): void
    {
        // 使用 Redis 存储查询优化配置
        $this->putSimpleFlag('redis_query_optimized', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_redis_prefix', 'opt:', now()->addMinutes(10));
        $this->putSimpleFlag('cache_redis_compression', true, now()->addMinutes(10));
    }

    protected function bypassNonCriticalRedis(): void
    {
        $nonCriticalKeys = ['analytics:', 'temp:', 'session:', 'widgets:'];
        $this->putSimpleFlag('redis_bypass_keys', $nonCriticalKeys, now()->addMinutes(10));

        Log::info('Non-critical Redis operations bypassed');
    }

    protected function enableDatabaseCachePriority(): void
    {
        // 使用 Redis 存储缓存优先级配置
        $this->putSimpleFlag('cache_priority_order', ['database', 'file', 'redis'], now()->addMinutes(10));
    }

    protected function limitRedisConnections(): void
    {
        // 使用 Redis 存储连接限制配置
        $this->putSimpleFlag('database_redis_pool_size', 2, now()->addMinutes(10));
    }

    protected function enableRedisReadOnlyMode(): void
    {
        // 使用 Redis 存储只读模式配置
        $this->putSimpleFlag('redis_read_only', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_redis_read_only', true, now()->addMinutes(10));
    }

    protected function enableCompleteDatabaseFallback(): void
    {
        // 使用 Redis 存储数据库回退配置
        $this->putSimpleFlag('cache_default', 'database', now()->addMinutes(10));
        Log::info('Switched to database cache fallback');
    }

    protected function disableRedisWrites(): void
    {
        // 使用 Redis 存储写入禁用配置
        $this->putSimpleFlag('redis_writes_disabled', true, now()->addMinutes(10));
        $this->putSimpleFlag('cache_redis_read_only', true, now()->addMinutes(10));
    }

    protected function enableCompleteRedisBypass(): void
    {
        // 使用 Redis 存储完全绕过 Redis 配置
        $this->putSimpleFlag('cache_default', 'file', now()->addMinutes(10));
        $this->putSimpleFlag('redis_bypassed', true, now()->addMinutes(10));

        Log::warning('Redis completely bypassed');
    }

    // ========== Database 相关动作实现 ==========

    protected function enableQueryOptimization(): void
    {
        // 使用 Redis 存储查询优化配置
        $this->putSimpleFlag('database_query_cache', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_optimization_level', 'high', now()->addMinutes(10));
    }

    protected function prioritizeReadReplicas(): void
    {
        // 使用 Redis 存储读副本优先配置
        $this->putSimpleFlag('database_read_preference', 'replica', now()->addMinutes(10));
        $this->putSimpleFlag('database_default_read', 'mysql_read', now()->addMinutes(10));
    }

    protected function enableFrequentQueryCaching(): void
    {
        // 使用 Redis 存储频繁查询缓存配置
        $this->putSimpleFlag('database_query_cache_enabled', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_query_cache_ttl', 3600, now()->addMinutes(10));
    }

    protected function enableDatabaseReadOnlyMode(): void
    {
        // 使用 Redis 存储数据库只读模式配置
        $this->putSimpleFlag('database_read_only', true, now()->addMinutes(10));

        Log::warning('Database switched to read-only mode');
    }

    protected function disableComplexQueries(): void
    {
        // 使用 Redis 存储复杂查询禁用配置
        $this->putSimpleFlag('database_complex_queries_disabled', true, now()->addMinutes(10));
        $this->putSimpleFlag('complex_queries_disabled', true, now()->addMinutes(10));
    }

    protected function forceQueryCaching(): void
    {
        // 使用 Redis 存储强制查询缓存配置
        $this->putSimpleFlag('database_force_cache', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_cache_all_queries', true, now()->addMinutes(10));
    }

    protected function enableDatabaseEmergencyMode(): void
    {
        // 使用 Redis 存储数据库紧急模式配置
        $this->putSimpleFlag('database_emergency_mode', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_connection_limit', 1, now()->addMinutes(10));
        $this->putSimpleFlag('database_query_timeout', 5, now()->addMinutes(10));

        Log::warning('Database emergency mode activated');
    }

    protected function enableCacheOnlyResponses(): void
    {
        // 使用 Redis 存储仅缓存响应配置
        $this->putSimpleFlag('response_cache_only', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_cache_only', true, now()->addMinutes(10));
    }

    protected function enableMinimalDatabaseAccess(): void
    {
        // 使用 Redis 存储最小数据库访问配置
        $this->putSimpleFlag('database_minimal_access', true, now()->addMinutes(10));
        $this->putSimpleFlag('database_essential_queries_only', true, now()->addMinutes(10));
    }

    protected function enableCompleteDatabaseBypass(): void
    {
        // 使用 Redis 存储完全数据库绕过配置
        $this->putSimpleFlag('database_bypassed', true, now()->addMinutes(10));

        Log::critical('Database completely bypassed');
    }

    // ========== Performance Optimization 方法 ==========

    protected function adjustGarbageCollection(string $frequency): void
    {
        switch ($frequency) {
            case 'normal':
                // 默认GC策略，不做特殊处理
                break;

            case 'increased':
                // 每100个请求执行一次GC
                $requestCount = app()->has('request.count') ? app('request.count') : 0;
                if ($requestCount % 100 === 0) {
                    gc_collect_cycles();
                }
                break;

            case 'aggressive':
                // 每50个请求执行一次GC  
                $requestCount = app()->has('request.count') ? app('request.count') : 0;
                if ($requestCount % 50 === 0) {
                    gc_collect_cycles();
                }
                break;

            case 'continuous':
                // 每次请求都执行GC（极端情况）
                gc_collect_cycles();
                break;
        }
    }

    protected function adjustCacheStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'extend_ttl_50_percent':
                // 使用 Redis 存储缓存策略配置
                $this->putSimpleFlag('cache_default_ttl_extended', 5400, now()->addMinutes(10)); // 3600 * 1.5
                break;

            case 'cache_everything_possible':
                // 使用 Redis 存储积极缓存配置
                $this->putSimpleFlag('cache_aggressive', true, now()->addMinutes(10));
                $this->putSimpleFlag('cache_ttl', 7200, now()->addMinutes(10)); // 2小时
                $this->putSimpleFlag('view_cache', true, now()->addMinutes(10));
                $this->putSimpleFlag('route_cache', true, now()->addMinutes(10));
                break;

            case 'static_only':
                // 使用 Redis 存储静态策略配置
                $this->putSimpleFlag('cache_strategy', 'static_files', now()->addMinutes(10));
                $this->putSimpleFlag('dynamic_content', false, now()->addMinutes(10));
                break;

            case 'emergency_static':
                // 使用 Redis 存储紧急静态配置
                $this->putSimpleFlag('response_static_only', true, now()->addMinutes(10));
                $this->putSimpleFlag('cache_everything', false, now()->addMinutes(10));
                break;
        }
    }

    protected function adjustQueryTimeout(string $timeout): void
    {
        $baseTimeout = 30; // 默认超时时间

        switch ($timeout) {
            case 'reduce_20_percent':
                // 使用 Redis 存储查询超时配置
                $this->putSimpleFlag('database_timeout', $baseTimeout * 0.8, now()->addMinutes(10));
                break;

            case 'reduce_50_percent':
                $this->putSimpleFlag('database_timeout', $baseTimeout * 0.5, now()->addMinutes(10));
                break;

            case 'minimal':
                $this->putSimpleFlag('database_timeout', 5, now()->addMinutes(10));
                break;
        }
    }

    protected function adjustProcessingMode(string $mode): void
    {
        switch ($mode) {
            case 'health_check_only':
                // 使用 Redis 存储处理模式配置
                $this->putSimpleFlag('processing_mode', 'health_check', now()->addMinutes(10));
                $this->putSimpleFlag('routes_enabled', ['health', 'status', 'ping'], now()->addMinutes(10));
                break;
        }
    }

    // ========== 核心降级逻辑实现 ==========

    /**
     * 计算每个资源的降级级别
     * 
     * @param array $resourceStatus 资源状态
     * @return array 每个资源的降级级别 ['cpu' => 2, 'memory' => 1, 'redis' => 0, 'database' => 3]
     */
    protected function calculateResourceDegradationLevels(array $resourceStatus): array
    {
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);
        $resourceLevels = [];

        foreach ($resourceStatus as $resource => $usage) {
            $resourceLevels[$resource] = 0; // 默认级别为0

            if (!isset($thresholds[$resource])) {
                continue;
            }

            // 找到该资源应该触发的最高级别
            foreach ($thresholds[$resource] as $threshold => $level) {
                if ($usage >= $threshold) {
                    $resourceLevels[$resource] = max($resourceLevels[$resource], $level);
                }
            }
        }

        return $resourceLevels;
    }

    /**
     * 检查是否有资源需要恢复
     * 
     * @param array $previousLevels 之前的资源级别
     * @param array $currentLevels 当前的资源级别
     * @return bool 是否需要恢复
     */
    protected function checkResourcesNeedRecovery(array $previousLevels, array $currentLevels): bool
    {
        foreach ($previousLevels as $resource => $previousLevel) {
            $currentLevel = $currentLevels[$resource] ?? 0;
            if ($previousLevel > 0 && $currentLevel < $previousLevel) {
                return true; // 任何一个资源需要恢复都返回true
            }
        }
        return false;
    }

    /**
     * 检查是否有资源需要降级
     * 
     * @param array $previousLevels 之前的资源级别
     * @param array $currentLevels 当前的资源级别
     * @return bool 是否需要降级
     */
    protected function checkResourcesNeedDegradation(array $previousLevels, array $currentLevels): bool
    {
        foreach ($currentLevels as $resource => $currentLevel) {
            $previousLevel = $previousLevels[$resource] ?? 0;
            if ($currentLevel > $previousLevel) {
                return true; // 任何一个资源需要降级都返回true
            }
        }
        return false;
    }

    /**
     * 执行基于资源的逐级恢复
     * 
     * @param array $previousLevels 之前的资源级别
     * @param array $currentLevels 当前的资源级别
     * @param array $resourceStatus 资源状态
     * @return bool 是否执行了恢复
     */
    protected function executeResourceBasedGradualRecovery(array $previousLevels, array $currentLevels, array $resourceStatus): bool
    {
        $lastRecoveryAttempt = $this->getSimpleFlag('last_recovery_attempt', 0);
        $recoveryInterval = config('resilience.service_degradation.recovery.recovery_step_interval', 30);

        // 检查恢复间隔
        if (now()->timestamp - $lastRecoveryAttempt < $recoveryInterval) {
            return false; // 还未到恢复时间
        }

        // 验证恢复稳定性
        $recoveryValidationTime = config('resilience.service_degradation.recovery.recovery_validation_time', 120);
        if (!$this->validateRecoveryStability($resourceStatus, $recoveryValidationTime)) {
            Log::info('Recovery validation failed, system not stable enough');
            return false;
        }

        $strategies = config('resilience.service_degradation.strategies', []);
        $recoveredResources = [];
        $anyRecoveryExecuted = false;

        try {
            foreach ($previousLevels as $resource => $previousLevel) {
                $currentLevel = $currentLevels[$resource] ?? 0;

                if ($previousLevel > 0 && $currentLevel < $previousLevel) {
                    // 这个资源需要恢复，逐级恢复从 previousLevel 到 currentLevel
                    for ($level = $previousLevel; $level > $currentLevel; $level--) {
                        if (isset($strategies[$resource])) {
                            foreach ($strategies[$resource] as $threshold => $strategy) {
                                if (($strategy['level'] ?? 0) === $level) {
                                    $this->executeResourceRecoveryActions($resource, $strategy, $level);
                                    $recoveredResources[$resource][] = $level;
                                    $anyRecoveryExecuted = true;

                                    Log::info("Resource recovery executed", [
                                        'resource' => $resource,
                                        'from_level' => $level,
                                        'to_level' => $level - 1,
                                        'current_usage' => $resourceStatus[$resource] ?? 0
                                    ]);
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            if ($anyRecoveryExecuted) {
                // 更新Redis中的资源级别状态
                $this->putDegradationState('resource_degradation_levels', $currentLevels);
                $this->putDegradationState('last_recovery_attempt', now()->timestamp, now()->addHour());

                // 如果所有资源都恢复到0级别，清除相关状态
                $hasActiveDegradation = false;
                foreach ($currentLevels as $level) {
                    if ($level > 0) {
                        $hasActiveDegradation = true;
                        break;
                    }
                }

                if (!$hasActiveDegradation) {
                    $this->forgetDegradationState('resource_degradation_levels');
                    $this->forgetDegradationState('current_degradation_level');
                    $this->forgetDegradationState('current_degradation_state');
                    $this->forgetDegradationState('recovery_attempts_count');
                }

                Log::info('Resource-based gradual recovery completed', [
                    'recovered_resources' => $recoveredResources,
                    'previous_levels' => $previousLevels,
                    'current_levels' => $currentLevels
                ]);
            }

            return $anyRecoveryExecuted;
        } catch (\Exception $e) {
            Log::error('Resource-based recovery failed', [
                'error' => $e->getMessage(),
                'previous_levels' => $previousLevels,
                'current_levels' => $currentLevels,
                'resource_status' => $resourceStatus
            ]);

            return false;
        }
    }

    /**
     * 执行基于资源的逐级降级
     * 
     * @param array $previousLevels 之前的资源级别
     * @param array $currentLevels 当前的资源级别
     * @param array $resourceStatus 资源状态
     * @param Request $request 当前请求
     */
    protected function executeResourceBasedGradualDegradation(array $previousLevels, array $currentLevels, array $resourceStatus, Request $request): void
    {
        $strategies = config('resilience.service_degradation.strategies', []);
        $executedActions = [];

        foreach ($currentLevels as $resource => $currentLevel) {
            $previousLevel = $previousLevels[$resource] ?? 0;

            if ($currentLevel > $previousLevel) {
                // 这个资源需要降级，逐级执行从 previousLevel+1 到 currentLevel 的所有操作
                for ($level = $previousLevel + 1; $level <= $currentLevel; $level++) {
                    if (isset($strategies[$resource])) {
                        $usage = $resourceStatus[$resource] ?? 0;

                        foreach ($strategies[$resource] as $threshold => $strategy) {
                            if (($strategy['level'] ?? 0) === $level && $usage >= $threshold) {
                                $this->executeResourceStrategy($resource, $strategy, $request);

                                // 收集已执行的actions
                                if (isset($strategy['actions'])) {
                                    $executedActions = array_merge($executedActions, $strategy['actions']);
                                }

                                Log::info("Resource degradation level {$level} applied for resource: {$resource}", [
                                    'resource' => $resource,
                                    'level' => $level,
                                    'usage' => $usage,
                                    'threshold' => $threshold,
                                    'actions_count' => count($strategy['actions'] ?? [])
                                ]);
                                break;
                            }
                        }
                    }
                }
            }
        }

        // 保存资源级别状态到Redis
        $degradationState = [
            'resource_levels' => $currentLevels,
            'previous_resource_levels' => $previousLevels,
            'executed_actions' => array_unique($executedActions),
            'resource_status' => $resourceStatus,
            'timestamp' => now()->timestamp
        ];

        $this->putDegradationState('resource_degradation_levels', $currentLevels);
        $this->putDegradationState('current_degradation_level', max(array_values($currentLevels)));
        $this->putDegradationState('current_degradation_state', $degradationState);

        Log::warning("Resource-based gradual degradation executed", [
            'previous_levels' => $previousLevels,
            'current_levels' => $currentLevels,
            'total_actions' => count(array_unique($executedActions)),
            'resource_status' => $resourceStatus
        ]);
    }

    /**
     * 计算当前应有的降级级别（基于系统资源评估）
     * 
     * @param array $resourceStatus 资源状态
     * @return int 当前应有的降级级别
     */
    protected function calculateCurrentDegradationLevel(array $resourceStatus): int
    {
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);
        $maxLevel = 0;

        foreach ($resourceStatus as $resource => $usage) {
            if (!isset($thresholds[$resource])) {
                continue;
            }

            $resourceLevel = 0;
            // 找到该资源应该触发的最高级别
            foreach ($thresholds[$resource] as $threshold => $level) {
                if ($usage >= $threshold) {
                    $resourceLevel = max($resourceLevel, $level);
                }
            }

            $maxLevel = max($maxLevel, $resourceLevel);
        }

        return $maxLevel;
    }

    /**
     * 执行逐级降级：从之前级别到当前级别的所有操作
     * 
     * @param int $fromLevel 之前的降级级别
     * @param int $toLevel 当前需要的降级级别
     * @param array $resourceStatus 资源状态
     * @param Request $request 当前请求
     */
    protected function executeGradualDegradation(int $fromLevel, int $toLevel, array $resourceStatus, Request $request): void
    {
        $strategies = config('resilience.service_degradation.strategies', []);
        $executedActions = [];

        // 逐级执行从 fromLevel+1 到 toLevel 的所有降级操作
        for ($level = $fromLevel + 1; $level <= $toLevel; $level++) {
            foreach ($resourceStatus as $resource => $usage) {
                if (!isset($strategies[$resource])) {
                    continue;
                }

                // 找到该资源在当前级别的策略
                foreach ($strategies[$resource] as $threshold => $strategy) {
                    if (($strategy['level'] ?? 0) === $level && $usage >= $threshold) {
                        $this->executeResourceStrategy($resource, $strategy, $request);

                        // 收集已执行的actions
                        if (isset($strategy['actions'])) {
                            $executedActions = array_merge($executedActions, $strategy['actions']);
                        }

                        Log::info("Degradation level {$level} applied for resource: {$resource}", [
                            'resource' => $resource,
                            'level' => $level,
                            'usage' => $usage,
                            'threshold' => $threshold,
                            'actions_count' => count($strategy['actions'] ?? [])
                        ]);
                        break;
                    }
                }
            }
        }

        // 保存降级状态到Redis
        $degradationState = [
            'current_level' => $toLevel,
            'from_level' => $fromLevel,
            'executed_actions' => array_unique($executedActions),
            'resource_status' => $resourceStatus,
            'timestamp' => now()->timestamp
        ];

        $this->putDegradationState('current_degradation_level', $toLevel);
        $this->putDegradationState('current_degradation_state', $degradationState);

        Log::warning("Gradual degradation executed", [
            'from_level' => $fromLevel,
            'to_level' => $toLevel,
            'total_actions' => count(array_unique($executedActions)),
            'resource_status' => $resourceStatus
        ]);
    }

    /**
     * 执行逐级恢复：恢复指定级别的降级操作
     * 
     * @param int $fromLevel 当前降级级别
     * @param int $toLevel 目标降级级别
     * @param array $resourceStatus 资源状态
     * @return bool 是否执行了恢复
     */
    protected function executeGradualRecovery(int $fromLevel, int $toLevel, array $resourceStatus): bool
    {
        $lastRecoveryAttempt = $this->getSimpleFlag('last_recovery_attempt', 0);
        $recoveryInterval = config('resilience.service_degradation.recovery.recovery_step_interval', 30);

        // 检查恢复间隔
        if (now()->timestamp - $lastRecoveryAttempt < $recoveryInterval) {
            return false; // 还未到恢复时间
        }

        // 验证恢复稳定性
        $recoveryValidationTime = config('resilience.service_degradation.recovery.recovery_validation_time', 120);
        if (!$this->validateRecoveryStability($resourceStatus, $recoveryValidationTime)) {
            Log::info('Recovery validation failed, system not stable enough');
            return false;
        }

        $strategies = config('resilience.service_degradation.strategies', []);
        $recoveredActions = [];

        try {
            // 逐级恢复：从 fromLevel 降到 toLevel，恢复被移除的级别的操作
            for ($level = $fromLevel; $level > $toLevel; $level--) {
                foreach ($resourceStatus as $resource => $usage) {
                    if (!isset($strategies[$resource])) {
                        continue;
                    }

                    // 找到该资源在当前级别的策略
                    foreach ($strategies[$resource] as $threshold => $strategy) {
                        if (($strategy['level'] ?? 0) === $level) {
                            $this->executeResourceRecoveryActions($resource, $strategy, $level);

                            // 收集已恢复的actions
                            if (isset($strategy['actions'])) {
                                $recoveredActions = array_merge($recoveredActions, $strategy['actions']);
                            }

                            Log::info("Recovery level {$level} executed for resource: {$resource}", [
                                'resource' => $resource,
                                'level' => $level,
                                'usage' => $usage,
                                'actions_count' => count($strategy['actions'] ?? [])
                            ]);
                            break;
                        }
                    }
                }
            }

            // 更新降级状态
            if ($toLevel === 0) {
                // 完全恢复
                $this->forgetDegradationState('current_degradation_level');
                $this->forgetDegradationState('current_degradation_state');
                $this->forgetDegradationState('recovery_attempts_count');
            } else {
                // 部分恢复
                $degradationState = [
                    'current_level' => $toLevel,
                    'from_level' => $fromLevel,
                    'recovered_actions' => array_unique($recoveredActions),
                    'resource_status' => $resourceStatus,
                    'timestamp' => now()->timestamp
                ];

                $this->putDegradationState('current_degradation_level', $toLevel);
                $this->putDegradationState('current_degradation_state', $degradationState);
            }

            $this->putDegradationState('last_recovery_attempt', now()->timestamp, now()->addHour());

            Log::info("Gradual recovery executed", [
                'from_level' => $fromLevel,
                'to_level' => $toLevel,
                'recovered_actions' => count(array_unique($recoveredActions)),
                'resource_status' => $resourceStatus
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Gradual recovery failed', [
                'from_level' => $fromLevel,
                'to_level' => $toLevel,
                'error' => $e->getMessage(),
                'resource_status' => $resourceStatus
            ]);

            return false;
        }
    }

    /**
     * 执行资源恢复操作
     * 
     * @param string $resource 资源名称
     * @param array $strategy 策略配置
    /**
     * 计算降级信息 - 返回每个资源的详细降级信息
     * 
     * @param array $resourceStatus 资源状态
     * @return array 包含max_level和resource_strategies的数组
     */
    protected function calculateDegradationInfo(array $resourceStatus): array
    {
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);
        $strategies = config('resilience.service_degradation.strategies', []);
        $maxLevel = 0;
        $resourceStrategies = [];

        foreach ($resourceStatus as $resource => $usage) {
            if (!isset($thresholds[$resource])) {
                continue;
            }

            $resourceLevel = 0;
            $resourceStrategy = null;

            // 找到该资源应该触发的最高级别
            foreach ($thresholds[$resource] as $threshold => $level) {
                if ($usage >= $threshold) {
                    $resourceLevel = max($resourceLevel, $level);
                }
            }

            if ($resourceLevel > 0) {
                $maxLevel = max($maxLevel, $resourceLevel);

                // 找到对应的策略配置
                if (isset($strategies[$resource])) {
                    foreach ($strategies[$resource] as $threshold => $strategy) {
                        if ($usage >= $threshold && ($strategy['level'] ?? 0) === $resourceLevel) {
                            $resourceStrategy = $strategy;
                            break;
                        }
                    }
                }

                if ($resourceStrategy) {
                    $resourceStrategies[$resource] = [
                        'level' => $resourceLevel,
                        'usage' => $usage,
                        'strategy' => $resourceStrategy,
                        'threshold_triggered' => $threshold ?? 0
                    ];
                }
            }
        }

        return [
            'max_level' => $maxLevel,
            'resource_strategies' => $resourceStrategies,
            'total_affected_resources' => count($resourceStrategies)
        ];
    }

    /**
     * 计算降级级别 - 保持向后兼容性
     * 
     * @param array $resourceStatus
     * @return int
     */
    protected function calculateDegradationLevel(array $resourceStatus): int
    {
        $degradationInfo = $this->calculateDegradationInfo($resourceStatus);
        return $degradationInfo['max_level'];
    }

    /**
     * 执行降级策略 - 新版本：支持多资源并行降级
     */
    protected function executeDegradationStrategy($degradationInfo, array $resourceStatus, Request $request): void
    {
        // 如果是旧版本调用（传入的是int），转换为新格式
        if (is_int($degradationInfo)) {
            $level = $degradationInfo;
            $degradationInfo = $this->calculateDegradationInfo($resourceStatus);
            Log::warning('Using legacy executeDegradationStrategy call', ['level' => $level]);
        }

        $resourceStrategies = $degradationInfo['resource_strategies'];
        $maxLevel = $degradationInfo['max_level'];
        $executedActions = [];

        // 执行每个有问题资源的降级策略
        foreach ($resourceStrategies as $resource => $info) {
            $this->executeResourceStrategy($resource, $info['strategy'], $request);

            // 收集已执行的actions，避免重复执行
            if (isset($info['strategy']['actions'])) {
                $executedActions = array_merge($executedActions, $info['strategy']['actions']);
            }

            Log::info("Resource degradation strategy applied", [
                'resource' => $resource,
                'level' => $info['level'],
                'usage' => $info['usage'],
                'threshold' => $info['threshold_triggered'],
                'actions_count' => count($info['strategy']['actions'] ?? [])
            ]);
        }

        // 记录当前降级状态 - 保存详细信息
        $degradationState = [
            'max_level' => $maxLevel,
            'affected_resources' => array_keys($resourceStrategies),
            'resource_details' => $resourceStrategies,
            'executed_actions' => array_unique($executedActions),
            'timestamp' => now()->timestamp
        ];

        // 使用统一的缓存方法存储降级状态
        $stateStored = $this->putDegradationState('current_degradation_state', $degradationState);
        $levelStored = $this->putDegradationState('current_degradation_level', $maxLevel);

        // 验证缓存写入是否成功
        if ($stateStored && $levelStored) {
            $testRetrieve = $this->getDegradationState('current_degradation_level');
            if ($testRetrieve === $maxLevel) {
                Log::debug('Degradation state cached successfully', [
                    'level' => $maxLevel,
                    'degradation_state_size' => count($degradationState),
                    'affected_resources' => array_keys($resourceStrategies)
                ]);
            } else {
                Log::warning('Cache write verification failed', [
                    'written_level' => $maxLevel,
                    'retrieved_level' => $testRetrieve
                ]);
            }
        } else {
            Log::error('Failed to store degradation state in cache');
        }

        Log::warning('Multi-resource degradation strategy executed', [
            'max_level' => $maxLevel,
            'affected_resources_count' => count($resourceStrategies),
            'affected_resources' => array_keys($resourceStrategies),
            'total_actions' => count(array_unique($executedActions)),
            'resource_status' => $resourceStatus,
            'request_path' => $request->path(),
        ]);
    }

    /**
     * 执行特定资源的降级策略
     * 
     * 这是服务降级系统的核心执行引擎，根据不同资源（CPU、内存、Redis、数据库）
     * 的使用情况和配置的策略，分层次地应用各种降级措施。
     * 
     * 执行顺序说明：
     * 1. Actions（动作层） - 立即生效的快速响应措施
     * 2. Performance Optimizations（性能优化层） - 调整系统运行参数
     * 3. Memory Management（内存管理层） - 内存相关的优化和清理
     * 4. Fallback Strategies（后备策略层） - 切换到备用方案
     * 5. Database Strategies（数据库策略层） - 数据库访问优化
     *
     * @param string $resource 触发降级的资源类型 (cpu|memory|redis|database)
     * @param array $strategy 从配置文件中读取的完整策略配置
     * @param Request $request 当前HTTP请求实例，用于获取请求上下文信息
     * @return void
     * 
     * @example 典型的策略配置结构：
     * [
     *   'level' => 2,  // 降级级别
     *   'actions' => ['disable_heavy_analytics', 'reduce_log_verbosity'],
     *   'performance_optimizations' => ['gc_frequency' => 'increased'],
     *   'memory_management' => ['cache_cleanup' => 'aggressive'],
     *   'fallback_strategies' => ['cache_backend' => 'file'],
     *   'database_strategies' => ['query_strategy' => 'simple_queries_only']
     * ]
     */
    protected function executeResourceStrategy(string $resource, array $strategy, Request $request)
    {
        // 记录策略执行详情（如果启用）
        $this->logStrategyExecution($resource, $strategy, $request);

        // 第1层：执行即时响应动作（Actions Layer）
        // 这些是最快速、最直接的降级措施，通常在毫秒级生效
        // 包括：禁用功能、设置标志、调整配置等
        if (isset($strategy['actions'])) {
            $config = config('resilience.service_degradation', []);
            $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;

            if ($enableDetailedLogging) {
                Log::debug("Executing actions for resource: {$resource}", [
                    'actions' => $strategy['actions'],
                    'resource_type' => $resource
                ]);
            }
            $this->executeActions($strategy['actions'], $request);
        }

        // 第2层：应用性能优化策略（Performance Optimization Layer）
        // 调整系统级别的运行参数，影响后续请求的处理方式
        // 包括：GC频率、缓存策略、查询超时等
        if (isset($strategy['performance_optimizations'])) {
            Log::debug("Applying performance optimizations for resource: {$resource}", [
                'optimizations' => $strategy['performance_optimizations']
            ]);
            $this->applyPerformanceOptimizations($strategy['performance_optimizations']);
        }

        // 第3层：内存管理策略（Memory Management Layer）
        // 专门针对内存压力的管理措施
        // 包括：缓存清理、对象池配置、GC策略、内存限制等
        if (isset($strategy['memory_management'])) {
            Log::debug("Applying memory management for resource: {$resource}", [
                'management_config' => $strategy['memory_management']
            ]);
            $this->applyMemoryManagement($strategy['memory_management']);
        }

        // 第4层：后备策略（Fallback Strategies Layer）  
        // 当主要服务不可用时的备用方案
        // 包括：缓存后端切换、会话存储切换、数据策略调整等
        if (isset($strategy['fallback_strategies'])) {
            Log::debug("Applying fallback strategies for resource: {$resource}", [
                'fallback_config' => $strategy['fallback_strategies']
            ]);
            $this->applyFallbackStrategies($strategy['fallback_strategies']);
        }

        // 第5层：数据库策略（Database Strategies Layer）
        // 专门针对数据库访问的优化措施
        // 包括：连接策略、查询策略、缓存策略等
        if (isset($strategy['database_strategies'])) {
            Log::debug("Applying database strategies for resource: {$resource}", [
                'db_strategies' => $strategy['database_strategies']
            ]);
            $this->applyDatabaseStrategies($strategy['database_strategies']);
        }

        // 记录策略执行完成日志，便于监控和调试
        Log::info("Resource degradation strategy executed successfully", [
            'resource' => $resource,
            'strategy_level' => $strategy['level'] ?? 'unknown',
            'applied_layers' => [
                'actions' => isset($strategy['actions']),
                'performance_optimizations' => isset($strategy['performance_optimizations']),
                'memory_management' => isset($strategy['memory_management']),
                'fallback_strategies' => isset($strategy['fallback_strategies']),
                'database_strategies' => isset($strategy['database_strategies'])
            ],
            'execution_time' => now(),
            'request_path' => $request->path(),
            'request_method' => $request->method()
        ]);
    }

    /**
     * 检查恢复条件 - 新版本：支持多资源恢复检查
     */
    protected function checkRecoveryConditions(array $resourceStatus): void
    {
        // 获取调试日志配置
        $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;

        // 使用统一的缓存方法获取降级状态
        $currentDegradationState = $this->getDegradationState('current_degradation_state');
        $currentLevel = $this->getDegradationState('current_degradation_level', 0);

        // 添加调试日志
        if ($enableDetailedLogging) {
            Log::debug('Recovery conditions check', [
                'current_level' => $currentLevel,
                'has_degradation_state' => !is_null($currentDegradationState),
                'degradation_state_type' => gettype($currentDegradationState),
                'degradation_state_size' => is_array($currentDegradationState) ? count($currentDegradationState) : 0,
                'resource_status' => $resourceStatus
            ]);
        }

        if ($currentLevel <= 0 || !$currentDegradationState) {
            if ($enableDetailedLogging) {
                Log::debug('No recovery needed', [
                    'reason' => $currentLevel <= 0 ? 'no_degradation_level' : 'no_degradation_state',
                    'current_level' => $currentLevel,
                    'state_exists' => !is_null($currentDegradationState)
                ]);
            }
            return; // 没有降级，无需恢复
        }

        $recoveryConfig = config('resilience.service_degradation.recovery', []);
        $gradualRecovery = $recoveryConfig['gradual_recovery'] ?? true;
        $recoveryThreshold = $recoveryConfig['recovery_threshold_buffer'] ?? 5;
        $maxRecoveryAttempts = $recoveryConfig['max_recovery_attempts'] ?? 3;
        $recoveryValidationTime = $recoveryConfig['recovery_validation_time'] ?? 120;

        // 检查最大恢复尝试次数
        $recoveryAttempts = $this->getDegradationState('recovery_attempts_count', 0);
        if ($recoveryAttempts >= $maxRecoveryAttempts) {
            Log::warning('Maximum recovery attempts reached', [
                'attempts' => $recoveryAttempts,
                'max_attempts' => $maxRecoveryAttempts,
                'current_level' => $currentLevel
            ]);

            // 如果超过最大尝试次数，等待验证时间后重置尝试计数
            $lastFailedRecovery = $this->getDegradationState('last_failed_recovery_time', 0);
            if (now()->timestamp - $lastFailedRecovery >= $recoveryValidationTime) {
                $this->putDegradationState('recovery_attempts_count', 0, now()->addHour());
                Log::info('Recovery attempts counter reset after validation time');
            } else {
                return; // 还在验证等待期内，不进行恢复
            }
        }

        // 检查每个资源的恢复条件
        $recoverableResources = $this->checkResourceRecoveryConditions(
            $resourceStatus,
            $currentDegradationState,
            $recoveryThreshold
        );

        if (!empty($recoverableResources)) {
            // 检查恢复验证时间 - 确保系统在低负载状态下稳定运行足够时间
            if (!$this->validateRecoveryStability($resourceStatus, $recoveryValidationTime)) {
                Log::info('Recovery validation failed, system not stable enough', [
                    'validation_time' => $recoveryValidationTime,
                    'resource_status' => $resourceStatus,
                    'recoverable_resources' => array_keys($recoverableResources)
                ]);
                return;
            }

            if ($gradualRecovery) {
                $this->performGradualResourceRecovery($recoverableResources, $resourceStatus);
            } else {
                $this->performImmediateRecovery();
            }
        }
    }

    /**
     * 检查各个资源的恢复条件
     * 
     * @param array $resourceStatus 当前资源状态
     * @param array $currentDegradationState 当前降级状态
     * @param int $buffer 恢复缓冲区
     * @return array 可恢复的资源列表
     */
    protected function checkResourceRecoveryConditions(array $resourceStatus, array $currentDegradationState, int $buffer): array
    {
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);
        $recoverableResources = [];

        foreach ($currentDegradationState['resource_details'] as $resource => $degradationInfo) {
            $currentUsage = $resourceStatus[$resource] ?? 0;
            $degradationLevel = $degradationInfo['level'];

            if (!isset($thresholds[$resource])) {
                continue;
            }

            // 检查该资源是否可以从当前级别恢复
            $canRecover = true;
            foreach ($thresholds[$resource] as $threshold => $level) {
                if ($level === $degradationLevel) {
                    // 资源使用率需要低于触发阈值减去缓冲区
                    if ($currentUsage >= ($threshold - $buffer)) {
                        $canRecover = false;
                        break;
                    }
                }
            }

            if ($canRecover) {
                $recoverableResources[$resource] = [
                    'current_usage' => $currentUsage,
                    'degradation_info' => $degradationInfo,
                    'recovery_target_level' => max(0, $degradationLevel - 1)
                ];
            }
        }

        return $recoverableResources;
    }

    /**
     * 执行渐进式多资源恢复
     * 
     * @param array $recoverableResources 可恢复的资源列表
     * @param array $resourceStatus 当前资源状态
     */
    protected function performGradualResourceRecovery(array $recoverableResources, array $resourceStatus): void
    {
        $lastRecoveryAttempt = $this->getSimpleFlag('last_recovery_attempt', 0);
        $recoveryInterval = config('resilience.service_degradation.recovery.recovery_step_interval', 30);

        // 检查恢复间隔
        if (now()->timestamp - $lastRecoveryAttempt < $recoveryInterval) {
            return; // 还未到恢复时间
        }

        // 增加恢复尝试计数
        $recoveryAttempts = $this->getDegradationState('recovery_attempts_count', 0);
        $recoveryAttempts++;
        $this->putDegradationState('recovery_attempts_count', $recoveryAttempts, now()->addHour());

        $recoveredResources = [];

        try {
            foreach ($recoverableResources as $resource => $recoveryInfo) {
                $currentLevel = $recoveryInfo['degradation_info']['level'];
                $targetLevel = $recoveryInfo['recovery_target_level'];

                // 执行该资源的恢复动作
                $this->executeResourceRecovery($resource, $currentLevel, $targetLevel);

                $recoveredResources[$resource] = [
                    'from_level' => $currentLevel,
                    'to_level' => $targetLevel,
                    'usage' => $recoveryInfo['current_usage']
                ];

                Log::info("Resource recovery executed", [
                    'resource' => $resource,
                    'from_level' => $currentLevel,
                    'to_level' => $targetLevel,
                    'usage' => $recoveryInfo['current_usage'],
                    'attempt' => $recoveryAttempts
                ]);
            }

            // 更新降级状态
            $this->updateDegradationStateAfterRecovery($recoveredResources, $resourceStatus);

            $this->putDegradationState('last_recovery_attempt', now()->timestamp, now()->addHour());

            // 如果所有资源都恢复到0级别，执行完整恢复
            $newDegradationState = $this->getDegradationState('current_degradation_state', []);
            if (empty($newDegradationState['resource_details'])) {
                $this->performImmediateRecovery();
                $this->putDegradationState('recovery_attempts_count', 0, now()->addHour());
            }

            Log::info('Multi-resource gradual recovery completed', [
                'recovered_resources' => array_keys($recoveredResources),
                'recovery_details' => $recoveredResources,
                'attempt' => $recoveryAttempts
            ]);
        } catch (\Exception $e) {
            // 恢复失败，记录失败时间
            $this->putDegradationState('last_failed_recovery_time', now()->timestamp, now()->addHours(2));

            Log::error('Multi-resource recovery attempt failed', [
                'recoverable_resources' => array_keys($recoverableResources),
                'attempt_number' => $recoveryAttempts,
                'error' => $e->getMessage(),
                'resource_status' => $resourceStatus
            ]);

            throw $e;
        }
    }

    /**
     * 执行单个资源的恢复
     */
    protected function executeResourceRecovery(string $resource, int $fromLevel, int $toLevel): void
    {
        $strategies = config('resilience.service_degradation.strategies', []);

        if (!isset($strategies[$resource])) {
            return;
        }

        // 找到需要恢复的策略
        foreach ($strategies[$resource] as $threshold => $strategy) {
            if (($strategy['level'] ?? 0) === $fromLevel) {
                $this->executeResourceRecoveryActions($resource, $strategy, $fromLevel);
                break;
            }
        }
    }

    /**
     * 更新降级状态（移除已恢复的资源）
     */
    protected function updateDegradationStateAfterRecovery(array $recoveredResources, array $resourceStatus): void
    {
        $currentState = $this->getDegradationState('current_degradation_state', []);

        if (empty($currentState)) {
            return;
        }

        // 移除已完全恢复的资源
        foreach ($recoveredResources as $resource => $recoveryInfo) {
            if ($recoveryInfo['to_level'] === 0) {
                unset($currentState['resource_details'][$resource]);
            } else {
                // 更新资源的降级级别
                $currentState['resource_details'][$resource]['level'] = $recoveryInfo['to_level'];
            }
        }

        // 重新计算最大级别
        $newMaxLevel = 0;
        foreach ($currentState['resource_details'] as $resource => $details) {
            $newMaxLevel = max($newMaxLevel, $details['level']);
        }

        $currentState['max_level'] = $newMaxLevel;
        $currentState['affected_resources'] = array_keys($currentState['resource_details']);

        // 更新缓存
        if (empty($currentState['resource_details'])) {
            $this->forgetDegradationState('current_degradation_state');
            $this->forgetDegradationState('current_degradation_level');
        } else {
            $this->putDegradationState('current_degradation_state', $currentState);
            $this->putDegradationState('current_degradation_level', $newMaxLevel);
        }
    }

    /**
     * 判断是否可以恢复
     */
    protected function canRecover(array $resourceStatus, int $currentLevel, int $buffer): bool
    {
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);

        foreach ($resourceStatus as $resource => $usage) {
            if (!isset($thresholds[$resource])) {
                continue;
            }

            foreach ($thresholds[$resource] as $threshold => $level) {
                if ($level === $currentLevel) {
                    // 资源使用率需要低于触发阈值减去缓冲区
                    if ($usage >= ($threshold - $buffer)) {
                        return false; // 还不满足恢复条件
                    }
                }
            }
        }

        return true;
    }

    /**
     * 验证恢复稳定性
     * 
     * 确保系统在低负载状态下已经稳定运行足够长时间，避免因为短暂的资源波动
     * 而进行不必要的恢复操作。这有助于减少系统震荡和频繁的状态切换。
     * 
     * @param array $resourceStatus 当前资源状态
     * @param int $validationTime 需要稳定的时间（秒）
     * @return bool 是否通过稳定性验证
     */
    protected function validateRecoveryStability(array $resourceStatus, int $validationTime): bool
    {
        $currentTime = now()->timestamp;
        $stabilityKey = 'recovery_stability_check';

        // 获取稳定性检查历史记录
        $stabilityHistory = $this->getSimpleFlag($stabilityKey, []);

        // 清理过期的历史记录（只保留验证时间窗口内的记录）
        $stabilityHistory = array_filter($stabilityHistory, function ($record) use ($currentTime, $validationTime) {
            return ($currentTime - $record['timestamp']) <= $validationTime;
        });

        // 添加当前状态记录
        $stabilityHistory[] = [
            'timestamp' => $currentTime,
            'resource_status' => $resourceStatus,
        ];

        // 缓存更新后的历史记录
        $this->putSimpleFlag($stabilityKey, $stabilityHistory, now()->addHours(2));

        // 如果历史记录不足验证时间窗口，则不满足稳定性要求
        if (empty($stabilityHistory)) {
            return false;
        }

        $oldestRecord = reset($stabilityHistory);
        $timeDiff = $currentTime - $oldestRecord['timestamp'];

        if ($timeDiff < $validationTime) {
            Log::info('Recovery stability validation: insufficient time window', [
                'time_diff' => $timeDiff,
                'required_time' => $validationTime,
                'records_count' => count($stabilityHistory)
            ]);
            return false;
        }

        // 检查在验证时间窗口内，系统资源是否持续稳定
        $currentLevel = $this->getDegradationState('current_degradation_level', 0);
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);
        $recoveryBuffer = config('resilience.service_degradation.recovery.recovery_threshold_buffer', 5);

        foreach ($stabilityHistory as $record) {
            foreach ($record['resource_status'] as $resource => $usage) {
                if (!isset($thresholds[$resource])) {
                    continue;
                }

                foreach ($thresholds[$resource] as $threshold => $level) {
                    if ($level === $currentLevel) {
                        // 如果历史记录中有任何时刻资源使用率超过恢复阈值，则不稳定
                        if ($usage >= ($threshold - $recoveryBuffer)) {
                            Log::info('Recovery stability validation failed: resource spike detected', [
                                'resource' => $resource,
                                'usage' => $usage,
                                'threshold' => $threshold,
                                'buffer' => $recoveryBuffer,
                                'spike_time' => $record['timestamp']
                            ]);
                            return false;
                        }
                    }
                }
            }
        }

        Log::info('Recovery stability validation passed', [
            'validation_time' => $validationTime,
            'stable_duration' => $timeDiff,
            'records_analyzed' => count($stabilityHistory)
        ]);

        return true;
    }

    /**
     * 执行渐进式恢复
     */
    protected function performGradualRecovery(int $currentLevel, array $resourceStatus): void
    {
        $lastRecoveryAttempt = $this->getSimpleFlag('last_recovery_attempt', 0);
        $recoveryInterval = config('resilience.service_degradation.recovery.recovery_step_interval', 30);

        // 检查恢复间隔
        if (now()->timestamp - $lastRecoveryAttempt < $recoveryInterval) {
            return; // 还未到恢复时间
        }

        $newLevel = max(0, $currentLevel - 1);

        // 增加恢复尝试计数
        $recoveryAttempts = $this->getDegradationState('recovery_attempts_count', 0);
        $recoveryAttempts++;
        $this->putDegradationState('recovery_attempts_count', $recoveryAttempts, now()->addHour());

        Log::info('Gradual recovery initiated', [
            'from_level' => $currentLevel,
            'to_level' => $newLevel,
            'resource_status' => $resourceStatus,
            'recovery_attempt' => $recoveryAttempts
        ]);

        try {
            // 更新降级级别
            $this->putDegradationState('current_degradation_level', $newLevel, now()->addMinutes(30));
            $this->putDegradationState('last_recovery_attempt', now()->timestamp, now()->addHour());

            // 执行恢复动作
            $this->executeRecoveryActions($currentLevel, $newLevel);

            // 如果恢复成功，重置恢复尝试计数并记录恢复事件
            if ($newLevel === 0) {
                $this->performImmediateRecovery();
                $this->putDegradationState('recovery_attempts_count', 0, now()->addHour());
                $this->logRecoveryEvent($currentLevel, $newLevel, $resourceStatus, 'complete');
            } else {
                $this->logRecoveryEvent($currentLevel, $newLevel, $resourceStatus, 'gradual');
            }
        } catch (\Exception $e) {
            // 恢复失败，记录失败时间
            $this->putDegradationState('last_failed_recovery_time', now()->timestamp, now()->addHours(2));

            Log::error('Recovery attempt failed', [
                'from_level' => $currentLevel,
                'target_level' => $newLevel,
                'attempt_number' => $recoveryAttempts,
                'error' => $e->getMessage(),
                'resource_status' => $resourceStatus
            ]);

            // 恢复失败时，回滚降级级别
            $this->putDegradationState('current_degradation_level', $currentLevel, now()->addMinutes(30));

            throw $e; // 重新抛出异常以便上层处理
        }
    }

    /**
     * 执行立即恢复
     */
    protected function performImmediateRecovery(): void
    {
        // 清除所有降级标识 - 使用统一的缓存方法
        $forgetKeys = [
            // 状态类
            'current_degradation_level',
            'current_degradation_state',
            'last_recovery_attempt',
            'heavy_analytics_disabled',
            'recommendations_disabled',
            'background_jobs_disabled',
            'realtime_disabled',
            'redis_operations_reduced',
            'redis_read_only',
            'redis_writes_disabled',
            'database_read_only',
            'complex_queries_disabled',
            'log_verbosity_reduced',
            'cache_aggressive',
            'queue_sync',
            'session_driver',
            'response_minimal',
            'response_format',
            'gc_forced',
            'emergency_cpu_mode',
            'request_non_essential',
            'response_static_only',
            'cache_strategy',
            'cache_size_reduction',
            'file_processing_disabled',
            'filesystems_uploads_enabled',
            'image_processing',
            'file_processing',
            'minimal_object_creation',
            'object_pooling_enabled',
            'minimal_objects',
            'emergency_memory_cleanup_performed',
            'redis_bypass_keys',
            'cache_priority_order',
            'database_redis_pool_size',
            'database_redis_read_only',
            'cache_default',
            'cache_redis_operations_limit',
            'cache_local_fallback',
            'cache_fallback',
            'redis_query_optimized',
            'database_redis_prefix',
            'cache_redis_compression',
            'database_query_cache',
            'database_optimization_level',
            'database_read_preference',
            'database_default_read',
            'database_query_cache_enabled',
            'database_query_cache_ttl',
            'database_complex_queries_disabled',
            'database_force_cache',
            'database_cache_all_queries',
            'database_emergency_mode',
            'database_connection_limit',
            'database_query_timeout',
            'response_cache_only',
            'database_cache_only',
            'database_minimal_access',
            'database_essential_queries_only',
            'database_bypassed',
            'redis_bypassed',
            'static_responses_only',
            'view_cache',
            'route_cache',
            'dynamic_content',
            'cache_everything',
            'processing_mode',
            'routes_enabled',
            'database_timeout',
            'recommendations_disabled',
            'analytics_heavy_disabled',
            'request_too_large',
        ];
        foreach ($forgetKeys as $key) {
            $this->forgetDegradationState($key);
        }

        // 清除恢复相关的缓存
        $this->forgetDegradationState('recovery_attempts_count');
        $this->forgetDegradationState('last_failed_recovery_time');
        $this->forgetDegradationState('recovery_stability_check');

        // 重置配置项 - 使用 Redis 存储而非 config()
        $resetTrueKeys = [
            'filesystems_uploads_enabled',
            'image_processing',
            'file_processing',
            'websockets_enabled',
            'broadcasting_enabled',
            'realtime_features',
            'view_cache',
            'route_cache',
        ];
        foreach ($resetTrueKeys as $key) {
            $this->putSimpleFlag($key, true, now()->addMinutes(10));
        }
        $resetFalseKeys = [
            'database_read_only',
            'analytics_heavy_disabled',
            'recommendations_disabled',
            'file_processing_disabled',
            'response_minimal',
            'static_responses_only',
            'minimal_object_creation',
            'cache_local_fallback',
            'redis_bypassed',
            'database_bypassed',
            'database_cache_only',
            'request_non_essential',
            'request_too_large',
            'cache_aggressive',
            'log_verbosity_reduced',
            'complex_queries_disabled',
            'redis_operations_reduced',
            'redis_read_only',
            'redis_writes_disabled',
            'background_jobs_disabled',
            'heavy_analytics_disabled',
            'realtime_disabled',
            'database_complex_queries_disabled',
            'minimal_objects',
            'object_pooling_enabled',
            'emergency_cpu_mode',
            'queue_sync',
            'session_driver',
            'response_format',
            'gc_forced',
            'cache_strategy',
            'cache_size_reduction',
            'emergency_memory_cleanup_performed',
            'database_redis_read_only',
            'database_query_cache',
            'database_optimization_level',
            'database_read_preference',
            'database_default_read',
            'database_query_cache_enabled',
            'database_query_cache_ttl',
            'database_force_cache',
            'database_cache_all_queries',
            'database_emergency_mode',
            'database_connection_limit',
            'database_query_timeout',
            'response_cache_only',
            'database_cache_only',
            'database_minimal_access',
            'database_essential_queries_only',
            'database_bypassed',
            'static_responses_only',
            'dynamic_content',
            'cache_everything',
            'processing_mode',
            'routes_enabled',
            'database_timeout',
            'cache_redis_operations_limit',
            'cache_local_fallback',
            'cache_fallback',
            'redis_query_optimized',
            'database_redis_prefix',
            'cache_redis_compression',
            'redis_bypass_keys',
            'cache_priority_order',
            'database_redis_pool_size',
            'cache_default',
        ];
        foreach ($resetFalseKeys as $key) {
            $this->putSimpleFlag($key, false, now()->addMinutes(10));
        }
        $this->putSimpleFlag('cache_default', 'file', now()->addMinutes(10));

        Log::info('Service degradation recovery completed', [
            'timestamp' => now(),
            'recovery_type' => 'complete'
        ]);
    }

    /**
     * 执行恢复动作 - 基于配置文件中的策略和实际降级状态
     * 
     * 该方法根据配置文件中定义的策略来恢复降级措施，而不是硬编码的恢复逻辑。
     * 它会检查每个resource的策略配置，确定在指定级别下应该恢复哪些actions。
     */
    protected function executeRecoveryActions(int $fromLevel, int $toLevel): void
    {
        $strategies = config('resilience.service_degradation.strategies', []);
        $recoveredActions = [];

        // 遍历所有资源策略，找到需要恢复的actions
        foreach ($strategies as $resource => $resourceStrategies) {
            foreach ($resourceStrategies as $threshold => $strategy) {
                $strategyLevel = $strategy['level'] ?? 0;

                // 如果策略级别在恢复范围内，执行恢复
                if ($strategyLevel <= $fromLevel && $strategyLevel > $toLevel) {
                    $this->executeResourceRecoveryActions($resource, $strategy, $strategyLevel);

                    // 记录已恢复的actions以便日志
                    if (isset($strategy['actions'])) {
                        $recoveredActions = array_merge($recoveredActions, $strategy['actions']);
                    }
                }
            }
        }

        // 记录恢复日志
        Log::info('Recovery actions executed based on configuration', [
            'from_level' => $fromLevel,
            'to_level' => $toLevel,
            'recovered_actions' => array_unique($recoveredActions),
            'strategies_checked' => count($strategies)
        ]);
    }

    /**
     * 执行特定资源的恢复actions
     * 
     * @param string $resource 资源类型 (cpu|memory|redis|mysql)
     * @param array $strategy 策略配置
     * @param int $level 恢复的级别
     */
    protected function executeResourceRecoveryActions(string $resource, array $strategy, int $level): void
    {
        // 执行actions的恢复
        if (isset($strategy['actions'])) {
            $this->recoverActions($strategy['actions'], $resource, $level);
        }

        // 恢复性能优化设置
        if (isset($strategy['performance_optimizations'])) {
            $this->recoverPerformanceOptimizations($strategy['performance_optimizations'], $resource, $level);
        }

        // 恢复内存管理设置
        if (isset($strategy['memory_management'])) {
            $this->recoverMemoryManagement($strategy['memory_management'], $resource, $level);
        }

        // 恢复后备策略
        if (isset($strategy['fallback_strategies'])) {
            $this->recoverFallbackStrategies($strategy['fallback_strategies'], $resource, $level);
        }

        // 恢复数据库策略
        if (isset($strategy['database_strategies'])) {
            // $this->recoverDatabaseStrategies($strategy['database_strategies'], $resource, $level); // 已移除未定义方法
        }

        Log::debug("Resource recovery actions executed", [
            'resource' => $resource,
            'level' => $level,
            'actions_count' => count($strategy['actions'] ?? []),
            'timestamp' => now()
        ]);
    }

    /**
     * 设置降级上下文信息
     */
    protected function setDegradationContext(Request $request, int $level, array $resourceStatus): void
    {
        // 在请求中设置降级标识
        $request->attributes->set('degraded', true);
        $request->attributes->set('degradation_level', $level);
        $request->attributes->set('resource_status', $resourceStatus);

        Log::info('Service degradation context set', [
            'route' => $request->route() ? $request->route()->getName() : null,
            'degradation_level' => $level,
            'resource_status' => $resourceStatus,
            'ip' => $request->ip()
        ]);
    }

    /**
     * 创建降级响应
     */
    protected function createDegradedResponse(int $level)
    {
        $levelConfig = config("resilience.service_degradation.levels.{$level}", []);

        $response = response()->json(
            $levelConfig['response_template'] ?? [
                'message' => 'Service temporarily degraded',
                'level' => $level
            ],
            $levelConfig['http_status'] ?? 503
        );

        // 添加缓存头
        if (isset($levelConfig['cache_headers'])) {
            foreach ($levelConfig['cache_headers'] as $header => $value) {
                $response->header($header, $value);
            }
        }

        return $response;
    }

    // ========== 内存管理、后备策略、数据库策略的实现方法 ==========

    /**
     * 应用内存管理策略
     */
    protected function applyMemoryManagement(array $management): void
    {
        foreach ($management as $key => $value) {
            switch ($key) {
                case 'cache_cleanup':
                    $this->performCacheCleanup($value);
                    break;
                case 'object_pooling':
                    $this->configureObjectPooling($value);
                    break;
                case 'gc_strategy':
                    $this->configureGcStrategy($value);
                    break;
                case 'memory_limits':
                    $this->adjustMemoryLimits($value);
                    break;
                case 'memory_monitoring':
                    $this->configureMemoryMonitoring($value);
                    break;
            }
        }
    }

    protected function performCacheCleanup(string $type): void
    {
        switch ($type) {
            case 'non_essential':
                $this->flushCacheWithTags(['temp', 'analytics']);
                break;
            case 'aggressive':
                $this->flushCacheWithTags(['temp', 'analytics', 'reports']);
                break;
            case 'emergency':
                $this->flushResilienceCache();
                break;
            case 'complete_clear':
                $this->flushResilienceCache();
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
                break;
        }
    }

    /**
     * 安全地清理带标签的缓存
     * 
     * 检查当前缓存驱动是否支持标签功能，如果不支持则使用特定键名清理
     * 
     * @param array $tags 要清理的缓存标签
     */
    protected function flushCacheWithTags(array $tags): void
    {
        // 使用Redis直接清理，不再依赖Laravel缓存标签
        try {
            foreach ($tags as $tag) {
                $this->flushKnownKeys($tag);
            }
        } catch (\Exception $e) {
            Log::warning('Tagged cache cleanup failed', [
                'tags' => $tags,
                'error' => $e->getMessage()
            ]);
            // 后备方案：清理所有弹性相关缓存
            $this->flushResilienceCache();
        }
    }

    /**
     * 清理已知的缓存键
     * 
     * 当无法使用标签或模式匹配时，清理预定义的具体缓存键
     * 
     * @param string $tag 标签名
     */
    protected function flushKnownKeys(string $tag): void
    {
        $knownKeys = [
            'temp' => [
                'temp_data',
                'temporary_cache',
                'temp_results',
                'cache_temp_analytics',
                'temp_user_data'
            ],
            'analytics' => [
                'analytics_data',
                'user_analytics',
                'page_views',
                'analytics_summary',
                'stats_cache',
                'metrics_data'
            ],
            'reports' => [
                'reports_cache',
                'monthly_report',
                'daily_stats',
                'report_data',
                'export_cache'
            ]
        ];

        if (isset($knownKeys[$tag])) {
            foreach ($knownKeys[$tag] as $key) {
                $this->forgetDegradationState($key);
            }
        }
    }

    protected function configureObjectPooling(string $mode): void
    {
        switch ($mode) {
            case 'enabled':
                // 使用 Redis 存储对象池配置
                $this->putSimpleFlag('object_pooling_enabled', true, now()->addMinutes(10));
                break;
            case 'strict_reuse':
                // 使用 Redis 存储严格对象池配置
                $this->putSimpleFlag('object_pooling_enabled', true, now()->addMinutes(10));
                $this->putSimpleFlag('object_pooling_strict', true, now()->addMinutes(10));
                break;
        }
    }

    protected function configureGcStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'frequent':
                ini_set('gc_probability', '100');
                break;
            case 'aggressive':
                ini_set('gc_probability', '100');
                gc_enable();
                break;
            case 'continuous':
                register_tick_function('gc_collect_cycles');
                break;
        }
    }

    protected function adjustMemoryLimits(string $type): void
    {
        switch ($type) {
            case 'strict_enforcement':
                ini_set('memory_limit', '128M');
                break;
            case 'emergency_limits':
                ini_set('memory_limit', '64M');
                break;
        }
    }

    protected function configureMemoryMonitoring(string $type): void
    {
        switch ($type) {
            case 'increased':
                // 使用 Redis 存储内存监控配置
                $this->putSimpleFlag('memory_monitoring_frequency', 'high', now()->addMinutes(10));
                break;
        }
    }

    /**
     * 应用后备策略
     */
    protected function applyFallbackStrategies(array $strategies): void
    {
        foreach ($strategies as $key => $value) {
            switch ($key) {
                case 'cache_backend':
                    $this->configureCacheBackend($value);
                    break;
                case 'session_storage':
                    $this->configureSessionStorage($value);
                    break;
                case 'data_strategy':
                    $this->configureDataStrategy($value);
                    break;
            }
        }
    }

    protected function configureCacheBackend(string $backend): void
    {
        // 使用 Redis 存储缓存后端配置
        $this->putSimpleFlag('cache_default', $backend, now()->addMinutes(10));
    }

    protected function configureSessionStorage(string $storage): void
    {
        switch ($storage) {
            case 'database_fallback':
                // 使用 Redis 存储会话配置
                $this->putSimpleFlag('session_driver', 'database', now()->addMinutes(10));
                break;
            case 'file_system':
                $this->putSimpleFlag('session_driver', 'file', now()->addMinutes(10));
                break;
            case 'database':
                $this->putSimpleFlag('session_driver', 'database', now()->addMinutes(10));
                break;
            case 'disabled':
                $this->putSimpleFlag('session_driver', 'array', now()->addMinutes(10));
                break;
        }
    }

    protected function configureDataStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'hybrid_caching':
                // 使用 Redis 存储数据策略配置
                $this->putSimpleFlag('data_strategy', 'hybrid', now()->addMinutes(10));
                break;
            case 'database_first':
                $this->putSimpleFlag('data_strategy', 'database_priority', now()->addMinutes(10));
                break;
            case 'no_redis_dependency':
                $this->putSimpleFlag('data_redis_dependency', false, now()->addMinutes(10));
                break;
            case 'static_data_only':
                $this->putSimpleFlag('data_strategy', 'static', now()->addMinutes(10));
                break;
        }
    }

    /**
     * 应用数据库策略
     */
    protected function applyDatabaseStrategies(array $strategies): void
    {
        foreach ($strategies as $key => $value) {
            switch ($key) {
                case 'connection_strategy':
                    $this->configureConnectionStrategy($value);
                    break;
                case 'query_strategy':
                    $this->configureQueryStrategy($value);
                    break;
                case 'cache_strategy':
                    $this->configureDatabaseCacheStrategy($value);
                    break;
            }
        }
    }

    protected function configureConnectionStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'read_replica_priority':
                // 使用 Redis 存储数据库连接策略配置
                $this->putSimpleFlag('database_read_preference', 'replica', now()->addMinutes(10));
                break;
            case 'read_only_connections':
                $this->putSimpleFlag('database_write_operations', false, now()->addMinutes(10));
                break;
            case 'minimal_connections':
                $this->putSimpleFlag('database_pool_size', 1, now()->addMinutes(10));
                break;
            case 'health_check_only':
                $this->putSimpleFlag('database_health_check_only', true, now()->addMinutes(10));
                break;
        }
    }

    protected function configureQueryStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'optimized_essential':
                // 使用 Redis 存储查询策略配置
                $this->putSimpleFlag('database_query_optimization', 'essential', now()->addMinutes(10));
                break;
            case 'simple_queries_only':
                $this->putSimpleFlag('database_complex_queries', false, now()->addMinutes(10));
                break;
            case 'cache_first_mandatory':
                $this->putSimpleFlag('database_cache_first', true, now()->addMinutes(10));
                break;
            case 'no_database_access':
                $this->putSimpleFlag('database_access', false, now()->addMinutes(10));
                break;
        }
    }

    protected function configureDatabaseCacheStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'aggressive_query_cache':
                // 使用 Redis 存储数据库缓存策略配置
                $this->putSimpleFlag('database_query_cache', true, now()->addMinutes(10));
                $this->putSimpleFlag('database_cache_ttl', 3600, now()->addMinutes(10));
                break;
            case 'mandatory_caching':
                $this->putSimpleFlag('database_force_cache', true, now()->addMinutes(10));
                break;
            case 'cache_everything_possible':
                $this->putSimpleFlag('database_cache_all', true, now()->addMinutes(10));
                $this->putSimpleFlag('database_cache_ttl', 7200, now()->addMinutes(10));
                break;
            case 'static_fallback_only':
                $this->putSimpleFlag('database_fallback', 'static', now()->addMinutes(10));
                break;
        }
    }

    /**
     * 记录资源监控数据
     * 
     * @param array $resourceStatus 资源状态
     * @param array $config 配置数组（可选）
     */
    protected function logResourceMonitoring(array $resourceStatus, array $config = [])
    {
        // 使用传入的配置或者实例配置
        $serviceConfig = $config ?: $this->config;
        $enableDetailedLogging = $serviceConfig['monitoring']['enable_detailed_logging'] ?? false;
        $enableResourceMonitoringLog = $serviceConfig['monitoring']['log_resource_monitoring'] ?? false;

        if ($enableDetailedLogging || $enableResourceMonitoringLog) {
            $logData = [
                'event' => 'resource_monitoring',
                'timestamp' => time()
            ];

            if ($enableDetailedLogging) {
                $logData['resource_status'] = $resourceStatus;
                $logData['thresholds'] = $serviceConfig['resource_thresholds'] ?? [];
            } else {
                // 只记录关键资源状态
                $logData['cpu_usage'] = $resourceStatus['cpu'] ?? 0;
                $logData['memory_usage'] = $resourceStatus['memory'] ?? 0;
            }

            Log::info('Resource monitoring data', $logData);
        }
    }

    /**
     * 记录降级事件
     * 
     * @param Request $request 请求对象
     * @param int $level 降级级别
     * @param array $resourceStatus 资源状态
     * @param string $mode 降级模式
     * @param array $config 配置数组
     */
    protected function logDegradationEvent(Request $request, int $level, array $resourceStatus, string $mode)
    {
        $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;

        if ($enableDetailedLogging) {
            $logData = [
                'event' => 'service_degradation_activated',
                'degradation_level' => $level,
                'mode' => $mode,
                'route' => $request->route() ? $request->route()->getName() : null,
                'ip' => $request->ip(),
                'timestamp' => time()
            ];

            if ($enableDetailedLogging) {
                $logData['resource_status'] = $resourceStatus;
                $logData['request_path'] = $request->path();
                $logData['request_method'] = $request->method();
                $logData['user_agent'] = $request->userAgent();
            }

            Log::warning('Service degradation activated', $logData);
        }
    }

    /**
     * 记录恢复事件
     * 
     * @param int $fromLevel 原降级级别
     * @param int $toLevel 目标降级级别
     * @param array $resourceStatus 资源状态
     * @param string $recoveryType 恢复类型
     */
    protected function logRecoveryEvent(int $fromLevel, int $toLevel, array $resourceStatus, string $recoveryType = 'gradual')
    {
        $config = config('resilience.service_degradation', []);
        $logRecoveryEvents = $config['monitoring']['log_recovery_events'] ?? true;

        if ($logRecoveryEvents) {
            $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;

            $logData = [
                'event' => 'service_recovery',
                'from_level' => $fromLevel,
                'to_level' => $toLevel,
                'recovery_type' => $recoveryType,
                'timestamp' => time()
            ];

            if ($enableDetailedLogging) {
                $logData['resource_status'] = $resourceStatus;
            }

            if ($toLevel === 0) {
                Log::info('Service fully recovered', $logData);
            } else {
                Log::info('Service partially recovered', $logData);
            }
        }
    }

    /**
     * 记录策略执行详情
     * 
     * @param string $resource 资源类型
     * @param array $strategy 策略配置
     * @param Request $request 请求对象
     */
    protected function logStrategyExecution(string $resource, array $strategy, Request $request)
    {
        $config = config('resilience.service_degradation', []);
        $logStrategyExecution = $config['monitoring']['log_strategy_execution'] ?? false;

        if ($logStrategyExecution) {
            $logData = [
                'event' => 'strategy_execution',
                'resource' => $resource,
                'level' => $strategy['level'] ?? 'unknown',
                'actions_count' => isset($strategy['actions']) ? count($strategy['actions']) : 0,
                'request_path' => $request->path(),
                'timestamp' => time()
            ];

            $enableDetailedLogging = $this->config['monitoring']['enable_detailed_logging'] ?? false;
            if ($enableDetailedLogging) {
                $logData['strategy_details'] = $strategy;
                $logData['actions'] = $strategy['actions'] ?? [];
            }

            Log::info('Degradation strategy executed', $logData);
        }
    }

    // ========== 恢复方法实现 ==========

    /**
     * 恢复 actions - 基于配置和实际降级标识状态
     * 
     * @param array $actions 需要恢复的actions列表
     * @param string $resource 资源类型
     * @param int $level 恢复的级别
     */
    protected function recoverActions(array $actions, string $resource, int $level): void
    {
        foreach ($actions as $action) {
            switch ($action) {
                case 'disable_heavy_analytics':
                    if ($this->getSimpleFlag('heavy_analytics_disabled')) {
                        $this->forgetDegradationState('heavy_analytics_disabled');
                        Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    }
                    break;
                case 'reduce_log_verbosity':
                    if ($this->getSimpleFlag('log_verbosity_reduced')) {
                        $this->forgetDegradationState('log_verbosity_reduced');
                        Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    }
                    break;
                case 'reject_non_essential_requests':
                    $this->forgetDegradationState('reject_non_essential_requests');
                    Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    break;
                case 'reduce_cache_size_20_percent':
                    $this->forgetDegradationState('reduce_cache_size_20_percent');
                    Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    break;
                case 'disable_file_processing':
                    if ($this->getSimpleFlag('file_processing_disabled')) {
                        $this->forgetDegradationState('file_processing_disabled');
                        Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    }
                    break;
                case 'reject_large_requests':
                    $this->forgetDegradationState('reject_large_requests');
                    Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    break;
                case 'reduce_redis_operations':
                    if ($this->getSimpleFlag('redis_operations_reduced')) {
                        $this->forgetDegradationState('redis_operations_reduced');
                        Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    }
                    break;
                case 'redis_read_only_mode':
                    if ($this->getSimpleFlag('redis_read_only')) {
                        $this->forgetDegradationState('redis_read_only');
                        Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    }
                    break;
                case 'complete_redis_bypass':
                    if ($this->getSimpleFlag('redis_bypassed')) {
                        $this->forgetDegradationState('redis_bypassed');
                        Log::info("Recovered: {$action} for {$resource} at level {$level}");
                    }
                    break;
                default:
                    Log::debug("Recovery action not implemented: {$action} for {$resource}");
                    break;
            }
        }
    }

    /**
     * 恢复性能优化设置
     */
    protected function recoverPerformanceOptimizations(array $optimizations, string $resource, int $level): void
    {
        foreach ($optimizations as $key => $value) {
            switch ($key) {
                case 'cache_strategy':
                    break;

                case 'query_timeout':
                    break;

                case 'processing':
                    break;
            }
        }
    }

    /**
     * 恢复内存管理设置
     */
    protected function recoverMemoryManagement(array $management, string $resource, int $level): void
    {
        foreach ($management as $key => $value) {
            switch ($key) {
                case 'cache_cleanup':
                    // 内存管理的cache_cleanup通常不需要"恢复"，因为它是一次性动作
                    break;

                case 'object_pooling':
                    break;
            }
        }
    }

    /**
     * 恢复后备策略
     */
    protected function recoverFallbackStrategies(array $strategies, string $resource, int $level): void
    {
        // 预留：后备策略恢复逻辑（如有需要可补充）
    }

    protected function putSimpleFlag(string $key, $value, $ttl = null): bool
    {
        return $this->putDegradationState($key, $value, $ttl);
    }

    /**
     * 便利方法 - 获取简单的标志值
     */
    protected function getSimpleFlag(string $key, $default = null)
    {
        return $this->getDegradationState($key, $default);
    }

    /**
     * 统一存储降级状态到 Redis
     */
    protected function putDegradationState(string $key, $value, $ttl = null): bool
    {
        $redisKey = self::CACHE_PREFIX . $this->getKeyWithIpPort($key);
        if ($ttl) {
            return (bool)$this->redis->set($redisKey, serialize($value), 'EX', $this->convertTtlToSeconds($ttl));
        } else {
            return (bool)$this->redis->set($redisKey, serialize($value));
        }
    }

    /**
     * 统一获取降级状态
     */
    protected function getDegradationState(string $key, $default = null)
    {
        $redisKey = self::CACHE_PREFIX . $this->getKeyWithIpPort($key);
        $val = $this->redis->get($redisKey);
        return $val !== null ? unserialize($val) : $default;
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
        if ($this->currentRequest) {
            $ip = $this->currentRequest->ip();
            $port = $this->currentRequest->server('SERVER_PORT');
            return $ip . ':' . $port . ':' . $key;
        }
        return $key;
    }

    /**
     * 支持 Carbon/DateInterval/秒数的 TTL 转换
     */
    protected function convertTtlToSeconds($ttl): int
    {
        if (is_int($ttl)) return $ttl;
        if ($ttl instanceof \DateInterval) return (new \DateTime())->add($ttl)->getTimestamp() - time();
        if (method_exists($ttl, 'diffInSeconds')) return $ttl->diffInSeconds();
        if (method_exists($ttl, 'getTimestamp')) return $ttl->getTimestamp() - time();
        return 600;
    }
    protected function flushResilienceCache(): bool
    {
        try {
            // 获取当前客户端信息的键模式
            // $clientInfo = $this->getClientInfo(); // 已移除未定义方法
            $keyPattern = self::CACHE_PREFIX . '*';
            $keys = $this->redis->keys($keyPattern);

            if (!empty($keys)) {
                // 删除所有匹配的键
                $this->redis->del($keys);

                Log::info('Flushed resilience cache for client', [
                    // 'client_info' => $clientInfo, // 已移除未定义变量
                    'keys_deleted' => count($keys)
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to flush resilience cache", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 执行单个恢复操作
     * 
     * @param string $action 操作名称
     * @param string $resource 资源名称
     * @param int $level 级别
     */
    protected function executeRecoveryAction(string $action, string $resource, int $level): void
    {
        // 根据action类型执行相应的恢复操作
        switch ($action) {
            // CPU 相关恢复
            case 'disable_heavy_analytics':
                $this->putSimpleFlag('heavy_analytics_disabled', false, now()->addMinutes(10));
                break;
            case 'reduce_log_verbosity':
                $this->putSimpleFlag('log_verbosity_reduced', false, now()->addMinutes(10));
                break;
            case 'disable_background_jobs':
                $this->putSimpleFlag('background_jobs_disabled', false, now()->addMinutes(10));
                break;
            case 'cache_aggressive_mode':
                $this->putSimpleFlag('cache_aggressive', false, now()->addMinutes(10));
                break;
            case 'disable_recommendations_engine':
                $this->putSimpleFlag('recommendations_disabled', false, now()->addMinutes(10));
                break;
            case 'disable_real_time_features':
                $this->putSimpleFlag('realtime_disabled', false, now()->addMinutes(10));
                $this->putSimpleFlag('websockets_enabled', true, now()->addMinutes(10));
                $this->putSimpleFlag('broadcasting_enabled', true, now()->addMinutes(10));
                $this->putSimpleFlag('realtime_features', true, now()->addMinutes(10));
                break;
            case 'minimal_response_processing':
                $this->putSimpleFlag('response_minimal', false, now()->addMinutes(10));
                $this->putSimpleFlag('response_format', 'full', now()->addMinutes(10));
                break;
            case 'emergency_cpu_mode':
                $this->putSimpleFlag('emergency_cpu_mode', false, now()->addMinutes(10));
                $this->putSimpleFlag('queue_sync', true, now()->addMinutes(10));
                break;
            case 'static_responses_only':
                $this->putSimpleFlag('response_static_only', false, now()->addMinutes(10));
                $this->putSimpleFlag('cache_strategy', 'dynamic', now()->addMinutes(10));
                break;

            // Memory 相关恢复
            case 'disable_file_processing':
                $this->putSimpleFlag('file_processing_disabled', false, now()->addMinutes(10));
                $this->putSimpleFlag('filesystems_uploads_enabled', true, now()->addMinutes(10));
                $this->putSimpleFlag('image_processing', true, now()->addMinutes(10));
                $this->putSimpleFlag('file_processing', true, now()->addMinutes(10));
                break;
            case 'minimal_object_creation':
                $this->putSimpleFlag('minimal_object_creation', false, now()->addMinutes(10));
                $this->putSimpleFlag('object_pooling_enabled', false, now()->addMinutes(10));
                $this->putSimpleFlag('minimal_objects', false, now()->addMinutes(10));
                break;

            // Redis 相关恢复
            case 'reduce_redis_operations':
                $this->putSimpleFlag('redis_operations_reduced', false, now()->addMinutes(10));
                break;
            case 'enable_local_cache_fallback':
                $this->putSimpleFlag('cache_local_fallback', false, now()->addMinutes(10));
                $this->putSimpleFlag('cache_fallback', 'redis', now()->addMinutes(10));
                break;
            case 'redis_read_only_mode':
                $this->putSimpleFlag('redis_read_only', false, now()->addMinutes(10));
                $this->putSimpleFlag('database_redis_read_only', false, now()->addMinutes(10));
                break;
            case 'disable_redis_writes':
                $this->putSimpleFlag('redis_writes_disabled', false, now()->addMinutes(10));
                $this->putSimpleFlag('cache_redis_read_only', false, now()->addMinutes(10));
                break;

            // Database 相关恢复
            case 'enable_read_only_mode':
                $this->putSimpleFlag('database_read_only', false, now()->addMinutes(10));
                break;
            case 'disable_complex_queries':
                $this->putSimpleFlag('database_complex_queries_disabled', false, now()->addMinutes(10));
                $this->putSimpleFlag('complex_queries_disabled', false, now()->addMinutes(10));
                break;
            case 'database_emergency_mode':
                $this->putSimpleFlag('database_emergency_mode', false, now()->addMinutes(10));
                break;
            case 'minimal_database_access':
                $this->putSimpleFlag('database_minimal_access', false, now()->addMinutes(10));
                $this->putSimpleFlag('database_essential_queries_only', false, now()->addMinutes(10));
                break;
            case 'complete_database_bypass':
                $this->putSimpleFlag('database_bypassed', false, now()->addMinutes(10));
                break;

            default:
                Log::debug("Unknown recovery action: {$action}");
                break;
        }

        Log::debug("Recovery action executed: {$action}", [
            'resource' => $resource,
            'level' => $level
        ]);
    }

    /**
     * 恢复配置层
     */
    protected function recoverConfigurationLayer(string $layer, array $config, string $resource, int $level): void
    {
        Log::debug("Recovering configuration layer: {$layer}", [
            'resource' => $resource,
            'level' => $level,
            'config' => $config
        ]);

        // 根据不同的配置层执行相应的恢复操作
        switch ($layer) {
            case 'memory_management':
                $this->recoverMemoryManagement($config, $resource, $level);
                break;
            case 'fallback_strategies':
                $this->recoverFallbackStrategies($config, $resource, $level);
                break;
            case 'database_strategies':
                // $this->recoverDatabaseStrategies($config, $resource, $level); // 已移除未定义方法
                break;
        }
    }
}
