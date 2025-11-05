<?php

namespace OneLap\LaravelResilienceMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OneLap\LaravelResilienceMiddleware\Services\SystemMonitorService;

/**
 * 服务降级中间件
 * 根据系统压力自动降级服务
 */
class ServiceDegradationMiddleware
{
    protected $systemMonitor;

    public function __construct(SystemMonitorService $systemMonitor)
    {
        $this->systemMonitor = $systemMonitor;
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
        // 检查是否启用服务降级
        if (!config('resilience.service_degradation.enabled', true)) {
            return $next($request);
        }

        // 获取当前系统资源状态
        $resourceStatus = $this->systemMonitor->getResourceStatus();

        // 计算需要的降级级别
        $requiredDegradationLevel = $this->calculateDegradationLevel($resourceStatus);

        // 检查恢复条件
        $this->checkRecoveryConditions($resourceStatus);

        // 如果需要降级
        if ($requiredDegradationLevel > 0) {
            // 执行降级策略
            $this->executeDegradationStrategy($requiredDegradationLevel, $resourceStatus, $request);

            Log::info('Service degradation activated', [
                'route' => $request->route() ? $request->route()->getName() : null,
                'degradation_level' => $requiredDegradationLevel,
                'resource_status' => $resourceStatus,
                'mode' => $mode,
                'ip' => $request->ip()
            ]);

            // 根据模式和降级级别决定处理方式
            if ($mode === 'block' || ($mode === 'auto' && $requiredDegradationLevel >= 3)) {
                // 阻塞模式或高级别降级：直接返回降级响应
                return $this->createDegradedResponse($requiredDegradationLevel);
            } else {
                // 透传模式：设置降级标识，继续执行后续逻辑
                $this->setDegradationContext($request, $requiredDegradationLevel, $resourceStatus);
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
     * 设置降级上下文信息 - 透传模式使用
     *
     * @param Request $request
     * @param string $systemPressure
     * @param array $config
     * @param string $degradationLevel
     */


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
        $cachedResponse = cache($cacheKey);

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

    /**
     * 获取降级统计信息
     *
     * @return array
     */
    public function getDegradationStats()
    {
        $systemHealth = $this->systemMonitor->getSystemHealth();

        return [
            'system_pressure' => $this->systemMonitor->getSystemPressureLevel(),
            'cpu_usage' => $systemHealth['cpu_usage'],
            'memory_usage' => $systemHealth['memory_usage'],
            'degradation_active' => $this->systemMonitor->getSystemPressureLevel() !== 'low',
            'timestamp' => time()
        ];
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
                // CPU 相关actions
                case 'disable_heavy_analytics':
                    $this->disableHeavyAnalytics();
                    break;
                case 'reduce_log_verbosity':
                    $this->reduceLogVerbosity();
                    break;
                case 'disable_background_jobs':
                    $this->disableBackgroundJobs();
                    break;
                case 'cache_aggressive_mode':
                    $this->enableAggressiveCaching();
                    break;
                case 'disable_recommendations_engine':
                    $this->disableRecommendationsEngine();
                    break;
                case 'disable_real_time_features':
                    $this->disableRealTimeFeatures();
                    break;
                case 'minimal_response_processing':
                    $this->enableMinimalResponseProcessing();
                    break;
                case 'force_garbage_collection':
                    $this->forceGarbageCollection();
                    break;
                case 'emergency_cpu_mode':
                    $this->enableEmergencyCpuMode();
                    break;
                case 'reject_non_essential_requests':
                    $this->enableNonEssentialRequestRejection($request);
                    break;
                case 'static_responses_only':
                    $this->enableStaticResponsesOnly();
                    break;

                // Memory 相关actions
                case 'reduce_cache_size_20_percent':
                    $this->reduceCacheSize(0.2);
                    break;
                case 'reduce_cache_size_50_percent':
                    $this->reduceCacheSize(0.5);
                    break;
                case 'disable_file_processing':
                    $this->disableFileProcessing();
                    break;
                case 'minimal_object_creation':
                    $this->enableMinimalObjectCreation();
                    break;
                case 'emergency_memory_cleanup':
                    $this->performEmergencyMemoryCleanup();
                    break;
                case 'clear_all_non_critical_cache':
                    $this->clearNonCriticalCache();
                    break;
                case 'reject_large_requests':
                    $this->enableLargeRequestRejection($request);
                    break;

                // Redis 相关actions
                case 'reduce_redis_operations':
                    $this->reduceRedisOperations();
                    break;
                case 'enable_local_cache_fallback':
                    $this->enableLocalCacheFallback();
                    break;
                case 'optimize_redis_queries':
                    $this->optimizeRedisQueries();
                    break;
                case 'bypass_non_critical_redis':
                    $this->bypassNonCriticalRedis();
                    break;
                case 'database_cache_priority':
                    $this->enableDatabaseCachePriority();
                    break;
                case 'limit_redis_connections':
                    $this->limitRedisConnections();
                    break;
                case 'redis_read_only_mode':
                    $this->enableRedisReadOnlyMode();
                    break;
                case 'complete_database_fallback':
                    $this->enableCompleteDatabaseFallback();
                    break;
                case 'disable_redis_writes':
                    $this->disableRedisWrites();
                    break;
                case 'complete_redis_bypass':
                    $this->enableCompleteRedisBypass();
                    break;

                // Database 相关actions
                case 'enable_query_optimization':
                    $this->enableQueryOptimization();
                    break;
                case 'prioritize_read_replicas':
                    $this->prioritizeReadReplicas();
                    break;
                case 'cache_frequent_queries':
                    $this->enableFrequentQueryCaching();
                    break;
                case 'enable_read_only_mode':
                    $this->enableDatabaseReadOnlyMode();
                    break;
                case 'disable_complex_queries':
                    $this->disableComplexQueries();
                    break;
                case 'force_query_caching':
                    $this->forceQueryCaching();
                    break;
                case 'database_emergency_mode':
                    $this->enableDatabaseEmergencyMode();
                    break;
                case 'cache_only_responses':
                    $this->enableCacheOnlyResponses();
                    break;
                case 'minimal_database_access':
                    $this->enableMinimalDatabaseAccess();
                    break;
                case 'complete_database_bypass':
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
        // 设置应用实例标志，供其他服务检查
        app()->instance('analytics.heavy.disabled', true);

        // 设置配置项
        config(['analytics.heavy_processing' => false]);

        // 缓存标志，避免重复检查
        cache(['heavy_analytics_disabled' => true], now()->addMinutes(10));

        Log::info('Heavy analytics disabled due to system pressure');
    }

    protected function reduceLogVerbosity(): void
    {
        // 临时提高日志级别，减少输出
        config(['logging.level' => 'warning']);

        // 禁用调试日志
        config(['app.debug' => false]);

        cache(['log_verbosity_reduced' => true], now()->addMinutes(10));
    }

    protected function disableBackgroundJobs(): void
    {
        // 暂停队列处理
        cache(['background_jobs_disabled' => true], now()->addMinutes(10));

        // 增加队列延迟
        config(['queue.connections.default.delay' => 3600]);

        Log::info('Background jobs disabled due to system pressure');
    }

    protected function enableAggressiveCaching(): void
    {
        // 延长缓存TTL
        config([
            'cache.aggressive' => true,
            'cache.default_ttl' => config('cache.default_ttl', 3600) * 2,
            'view.cache' => true,
        ]);

        Log::info('Aggressive caching enabled');
    }

    protected function disableRecommendationsEngine(): void
    {
        app()->instance('recommendations.disabled', true);
        cache(['recommendations_disabled' => true], now()->addMinutes(10));

        Log::info('Recommendations engine disabled');
    }

    protected function disableRealTimeFeatures(): void
    {
        config([
            'websockets.enabled' => false,
            'broadcasting.enabled' => false,
            'realtime.features' => false,
        ]);

        cache(['realtime_disabled' => true], now()->addMinutes(10));
    }

    protected function enableMinimalResponseProcessing(): void
    {
        app()->instance('response.minimal', true);
        config(['response.format' => 'minimal']);
    }

    protected function forceGarbageCollection(): void
    {
        gc_collect_cycles();
        cache(['gc_forced' => now()], now()->addMinute());
    }

    protected function enableEmergencyCpuMode(): void
    {
        config([
            'app.emergency_cpu_mode' => true,
            'queue.sync' => false,
            'session.driver' => 'array', // 避免session写入
        ]);
    }

    protected function enableNonEssentialRequestRejection(Request $request): void
    {
        // 从配置文件中获取非必要路径列表
        $nonEssentialPaths = config('resilience.service_degradation.non_essential_paths', []);

        $currentPath = $request->path();

        foreach ($nonEssentialPaths as $path) {
            // 兼容低版本 PHP，使用 substr 替代 str_starts_with
            if (substr($currentPath, 0, strlen($path)) === $path) {
                app()->instance('request.non_essential', true);

                // 记录非必要请求被标记的日志
                Log::info('Non-essential request marked for potential rejection', [
                    'path' => $currentPath,
                    'matched_pattern' => $path,
                    'method' => $request->method(),
                    'ip' => $request->ip()
                ]);

                break;
            }
        }
    }

    protected function enableStaticResponsesOnly(): void
    {
        config([
            'response.static_only' => true,
            'cache.strategy' => 'static_files',
        ]);

        app()->instance('static_responses_only', true);
    }

    // ========== Memory 相关动作实现 ==========

    protected function reduceCacheSize(float $percentage): void
    {
        cache(['cache_size_reduction' => $percentage], now()->addMinutes(10));

        // 清理指定百分比的缓存
        $tagsToReduce = ['temp', 'analytics', 'reports'];
        foreach ($tagsToReduce as $tag) {
            if (rand(1, 100) <= $percentage * 100) {
                cache()->tags($tag)->flush();
            }
        }

        Log::info("Cache size reduced by {$percentage}%");
    }

    protected function disableFileProcessing(): void
    {
        config([
            'filesystems.uploads_enabled' => false,
            'image.processing' => false,
            'file.processing' => false,
        ]);

        app()->instance('file_processing_disabled', true);
    }

    protected function enableMinimalObjectCreation(): void
    {
        config([
            'object_pooling.enabled' => true,
            'minimal_objects' => true,
        ]);

        app()->instance('minimal_object_creation', true);
    }

    protected function performEmergencyMemoryCleanup(): void
    {
        // 清理各种缓存
        cache()->flush();

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

        // 清理容器中的非必要实例（如果容器支持）
        if (method_exists(app(), 'forgetInstance')) {
            app()->forgetInstance('analytics');
            app()->forgetInstance('recommendations');
        }

        Log::warning('Emergency memory cleanup performed');
    }

    protected function clearNonCriticalCache(): void
    {
        $nonCriticalTags = ['analytics', 'recommendations', 'reports', 'temp', 'widgets'];

        foreach ($nonCriticalTags as $tag) {
            cache()->tags($tag)->flush();
        }

        Log::info('Non-critical cache cleared');
    }

    protected function enableLargeRequestRejection(Request $request): void
    {
        $maxSize = 1024 * 1024; // 1MB
        $contentLength = $request->header('content-length', 0);

        if ($contentLength > $maxSize) {
            app()->instance('request.too_large', true);
        }
    }

    // ========== Redis 相关动作实现 ==========

    protected function reduceRedisOperations(): void
    {
        config(['cache.redis.operations_limit' => 100]);
        cache(['redis_operations_reduced' => true], now()->addMinutes(10));

        Log::info('Redis operations reduced');
    }

    protected function enableLocalCacheFallback(): void
    {
        config(['cache.fallback' => 'array']);
        app()->instance('cache.local_fallback', true);
    }

    protected function optimizeRedisQueries(): void
    {
        config([
            'database.redis.options.prefix' => 'opt:',
            'cache.redis.compression' => true,
        ]);
    }

    protected function bypassNonCriticalRedis(): void
    {
        $nonCriticalKeys = ['analytics:', 'temp:', 'session:', 'widgets:'];
        cache(['redis_bypass_keys' => $nonCriticalKeys], now()->addMinutes(10));

        Log::info('Non-critical Redis operations bypassed');
    }

    protected function enableDatabaseCachePriority(): void
    {
        config(['cache.priority_order' => ['database', 'file', 'redis']]);
    }

    protected function limitRedisConnections(): void
    {
        config(['database.redis.options.pool_size' => 2]);
    }

    protected function enableRedisReadOnlyMode(): void
    {
        config(['database.redis.read_only' => true]);
        cache(['redis_read_only' => true], now()->addMinutes(10));
    }

    protected function enableCompleteDatabaseFallback(): void
    {
        config(['cache.default' => 'database']);
        Log::info('Switched to database cache fallback');
    }

    protected function disableRedisWrites(): void
    {
        config(['cache.redis.read_only' => true]);
        cache(['redis_writes_disabled' => true], now()->addMinutes(10));
    }

    protected function enableCompleteRedisBypass(): void
    {
        config(['cache.default' => 'file']);
        app()->instance('redis.bypassed', true);

        Log::warning('Redis completely bypassed');
    }

    // ========== Database 相关动作实现 ==========

    protected function enableQueryOptimization(): void
    {
        config([
            'database.default_options' => [
                'query_cache' => true,
                'optimization_level' => 'high',
            ]
        ]);
    }

    protected function prioritizeReadReplicas(): void
    {
        config(['database.read_preference' => 'replica']);

        // 如果有读写分离配置，优先使用读库
        if (config('database.connections.mysql_read')) {
            config(['database.default' => 'mysql_read']);
        }
    }

    protected function enableFrequentQueryCaching(): void
    {
        config([
            'database.query_cache.enabled' => true,
            'database.query_cache.ttl' => 3600,
        ]);
    }

    protected function enableDatabaseReadOnlyMode(): void
    {
        config(['database.read_only' => true]);
        cache(['database_read_only' => true], now()->addMinutes(10));

        Log::warning('Database switched to read-only mode');
    }

    protected function disableComplexQueries(): void
    {
        config(['database.complex_queries_disabled' => true]);
        cache(['complex_queries_disabled' => true], now()->addMinutes(10));
    }

    protected function forceQueryCaching(): void
    {
        config([
            'database.force_cache' => true,
            'database.cache_all_queries' => true,
        ]);
    }

    protected function enableDatabaseEmergencyMode(): void
    {
        config([
            'database.emergency_mode' => true,
            'database.connection_limit' => 1,
            'database.query_timeout' => 5,
        ]);

        Log::warning('Database emergency mode activated');
    }

    protected function enableCacheOnlyResponses(): void
    {
        config(['response.cache_only' => true]);
        app()->instance('database.cache_only', true);
    }

    protected function enableMinimalDatabaseAccess(): void
    {
        config([
            'database.minimal_access' => true,
            'database.essential_queries_only' => true,
        ]);
    }

    protected function enableCompleteDatabaseBypass(): void
    {
        config(['database.bypassed' => true]);
        app()->instance('database.bypassed', true);

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
                $currentTtl = config('cache.default_ttl', 3600);
                config(['cache.default_ttl' => $currentTtl * 1.5]);
                break;

            case 'cache_everything_possible':
                config([
                    'cache.aggressive' => true,
                    'cache.ttl' => 7200, // 2小时
                    'view.cache' => true,
                    'route.cache' => true,
                ]);
                break;

            case 'static_only':
                config([
                    'cache.strategy' => 'static_files',
                    'dynamic_content' => false,
                ]);
                break;

            case 'emergency_static':
                config([
                    'response.static_only' => true,
                    'cache.everything' => false,
                ]);
                break;
        }
    }

    protected function adjustQueryTimeout(string $timeout): void
    {
        $currentTimeout = config('database.connections.mysql.options.timeout', 30);

        switch ($timeout) {
            case 'reduce_20_percent':
                config(['database.connections.mysql.options.timeout' => $currentTimeout * 0.8]);
                break;

            case 'reduce_50_percent':
                config(['database.connections.mysql.options.timeout' => $currentTimeout * 0.5]);
                break;

            case 'minimal':
                config(['database.connections.mysql.options.timeout' => 5]);
                break;
        }
    }

    protected function adjustProcessingMode(string $mode): void
    {
        switch ($mode) {
            case 'health_check_only':
                config([
                    'processing.mode' => 'health_check',
                    'routes.enabled' => ['health', 'status', 'ping'],
                ]);
                break;
        }
    }

    // ========== 核心降级逻辑实现 ==========

    /**
     * 计算所需的降级级别
     */
    protected function calculateDegradationLevel(array $resourceStatus): int
    {
        $maxLevel = 0;
        $thresholds = config('resilience.service_degradation.resource_thresholds', []);

        foreach ($resourceStatus as $resource => $usage) {
            if (!isset($thresholds[$resource])) {
                continue;
            }

            foreach ($thresholds[$resource] as $threshold => $level) {
                if ($usage >= $threshold) {
                    $maxLevel = max($maxLevel, $level);
                }
            }
        }

        return $maxLevel;
    }

    /**
     * 执行降级策略
     */
    protected function executeDegradationStrategy(int $level, array $resourceStatus, Request $request): void
    {
        $strategies = config('resilience.service_degradation.strategies', []);

        foreach ($resourceStatus as $resource => $usage) {
            if (!isset($strategies[$resource])) {
                continue;
            }

            // 找到对应的策略配置
            $resourceStrategies = $strategies[$resource];
            foreach ($resourceStrategies as $threshold => $strategy) {
                if ($usage >= $threshold && ($strategy['level'] ?? 0) === $level) {
                    $this->executeResourceStrategy($resource, $strategy, $request);
                    break;
                }
            }
        }

        // 记录当前降级级别
        cache(['current_degradation_level' => $level], now()->addMinutes(30));

        Log::warning('Service degradation strategy executed', [
            'level' => $level,
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
    protected function executeResourceStrategy(string $resource, array $strategy, Request $request): void
    {
        // 第1层：执行即时响应动作（Actions Layer）
        // 这些是最快速、最直接的降级措施，通常在毫秒级生效
        // 包括：禁用功能、设置标志、调整配置等
        if (isset($strategy['actions'])) {
            Log::debug("Executing actions for resource: {$resource}", [
                'actions' => $strategy['actions'],
                'resource_type' => $resource
            ]);
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
     * 检查恢复条件
     */
    protected function checkRecoveryConditions(array $resourceStatus): void
    {
        $currentLevel = cache('current_degradation_level', 0);

        if ($currentLevel <= 0) {
            return; // 没有降级，无需恢复
        }

        $recoveryConfig = config('resilience.service_degradation.recovery', []);
        $gradualRecovery = $recoveryConfig['gradual_recovery'] ?? true;
        $recoveryThreshold = $recoveryConfig['recovery_threshold_buffer'] ?? 5;
        $maxRecoveryAttempts = $recoveryConfig['max_recovery_attempts'] ?? 3;
        $recoveryValidationTime = $recoveryConfig['recovery_validation_time'] ?? 120;

        // 检查最大恢复尝试次数
        $recoveryAttempts = cache('recovery_attempts_count', 0);
        if ($recoveryAttempts >= $maxRecoveryAttempts) {
            Log::warning('Maximum recovery attempts reached', [
                'attempts' => $recoveryAttempts,
                'max_attempts' => $maxRecoveryAttempts,
                'current_level' => $currentLevel
            ]);

            // 如果超过最大尝试次数，等待验证时间后重置尝试计数
            $lastFailedRecovery = cache('last_failed_recovery_time', 0);
            if (now()->timestamp - $lastFailedRecovery >= $recoveryValidationTime) {
                cache(['recovery_attempts_count' => 0], now()->addHour());
                Log::info('Recovery attempts counter reset after validation time');
            } else {
                return; // 还在验证等待期内，不进行恢复
            }
        }

        // 检查是否满足恢复条件
        if ($this->canRecover($resourceStatus, $currentLevel, $recoveryThreshold)) {
            // 检查恢复验证时间 - 确保系统在低负载状态下稳定运行足够时间
            if (!$this->validateRecoveryStability($resourceStatus, $recoveryValidationTime)) {
                Log::info('Recovery validation failed, system not stable enough', [
                    'validation_time' => $recoveryValidationTime,
                    'resource_status' => $resourceStatus
                ]);
                return;
            }

            if ($gradualRecovery) {
                $this->performGradualRecovery($currentLevel, $resourceStatus);
            } else {
                $this->performImmediateRecovery();
            }
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
        $stabilityHistory = cache($stabilityKey, []);

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
        cache([$stabilityKey => $stabilityHistory], now()->addHours(2));

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
        $currentLevel = cache('current_degradation_level', 0);
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
        $lastRecoveryAttempt = cache('last_recovery_attempt', 0);
        $recoveryInterval = config('resilience.service_degradation.recovery.recovery_step_interval', 30);

        // 检查恢复间隔
        if (now()->timestamp - $lastRecoveryAttempt < $recoveryInterval) {
            return; // 还未到恢复时间
        }

        $newLevel = max(0, $currentLevel - 1);

        // 增加恢复尝试计数
        $recoveryAttempts = cache('recovery_attempts_count', 0);
        $recoveryAttempts++;
        cache(['recovery_attempts_count' => $recoveryAttempts], now()->addHour());

        Log::info('Gradual recovery initiated', [
            'from_level' => $currentLevel,
            'to_level' => $newLevel,
            'resource_status' => $resourceStatus,
            'recovery_attempt' => $recoveryAttempts
        ]);

        try {
            // 更新降级级别
            cache(['current_degradation_level' => $newLevel], now()->addMinutes(30));
            cache(['last_recovery_attempt' => now()->timestamp], now()->addHour());

            // 执行恢复动作
            $this->executeRecoveryActions($currentLevel, $newLevel);

            // 如果恢复成功，重置恢复尝试计数
            if ($newLevel === 0) {
                $this->performImmediateRecovery();
                cache(['recovery_attempts_count' => 0], now()->addHour());
                Log::info('Complete recovery successful, attempts counter reset');
            } else {
                Log::info('Partial recovery successful', [
                    'new_level' => $newLevel,
                    'attempts_used' => $recoveryAttempts
                ]);
            }
        } catch (\Exception $e) {
            // 恢复失败，记录失败时间
            cache(['last_failed_recovery_time' => now()->timestamp], now()->addHours(2));

            Log::error('Recovery attempt failed', [
                'from_level' => $currentLevel,
                'target_level' => $newLevel,
                'attempt_number' => $recoveryAttempts,
                'error' => $e->getMessage(),
                'resource_status' => $resourceStatus
            ]);

            // 恢复失败时，回滚降级级别
            cache(['current_degradation_level' => $currentLevel], now()->addMinutes(30));

            throw $e; // 重新抛出异常以便上层处理
        }
    }

    /**
     * 执行立即恢复
     */
    protected function performImmediateRecovery(): void
    {
        // 清除所有降级标识
        cache()->forget('current_degradation_level');
        cache()->forget('last_recovery_attempt');
        cache()->forget('heavy_analytics_disabled');
        cache()->forget('recommendations_disabled');
        cache()->forget('background_jobs_disabled');
        cache()->forget('realtime_disabled');
        cache()->forget('redis_operations_reduced');
        cache()->forget('redis_read_only');
        cache()->forget('redis_writes_disabled');
        cache()->forget('database_read_only');
        cache()->forget('complex_queries_disabled');

        // 清除恢复相关的缓存
        cache()->forget('recovery_attempts_count');
        cache()->forget('last_failed_recovery_time');
        cache()->forget('recovery_stability_check');

        // 重置配置项
        config([
            'analytics.heavy_processing' => true,
            'filesystems.uploads_enabled' => true,
            'image.processing' => true,
            'file.processing' => true,
            'websockets.enabled' => true,
            'broadcasting.enabled' => true,
            'realtime.features' => true,
            'database.read_only' => false,
            'cache.default' => env('CACHE_DRIVER', 'file'),
        ]);

        // 重置应用实例标识
        app()->instance('analytics.heavy.disabled', false);
        app()->instance('recommendations.disabled', false);
        app()->instance('file_processing_disabled', false);
        app()->instance('response.minimal', false);
        app()->instance('static_responses_only', false);
        app()->instance('minimal_object_creation', false);
        app()->instance('cache.local_fallback', false);
        app()->instance('redis.bypassed', false);
        app()->instance('database.bypassed', false);
        app()->instance('database.cache_only', false);
        app()->instance('request.non_essential', false);
        app()->instance('request.too_large', false);

        Log::info('Service degradation recovery completed', [
            'timestamp' => now(),
            'recovery_type' => 'complete'
        ]);
    }

    /**
     * 执行恢复动作
     */
    protected function executeRecoveryActions(int $fromLevel, int $toLevel): void
    {
        // 根据恢复级别执行相应的恢复动作
        if ($fromLevel >= 4 && $toLevel < 4) {
            // 从紧急降级恢复
            config([
                'processing.mode' => 'normal',
                'routes.enabled' => null,
            ]);

            Log::info('Recovered from emergency degradation');
        }

        if ($fromLevel >= 3 && $toLevel < 3) {
            // 从重度降级恢复
            config([
                'response.static_only' => false,
                'cache.strategy' => 'normal',
            ]);

            app()->instance('static_responses_only', false);

            Log::info('Recovered from heavy degradation');
        }

        if ($fromLevel >= 2 && $toLevel < 2) {
            // 从中度降级恢复
            config([
                'websockets.enabled' => true,
                'broadcasting.enabled' => true,
                'realtime.features' => true,
            ]);

            cache()->forget('realtime_disabled');

            app()->instance('recommendations.disabled', false);

            Log::info('Recovered from moderate degradation');
        }

        if ($fromLevel >= 1 && $toLevel < 1) {
            // 从轻度降级恢复
            config([
                'analytics.heavy_processing' => true,
                'app.debug' => env('APP_DEBUG', false),
                'logging.level' => env('LOG_LEVEL', 'debug'),
            ]);

            cache()->forget('heavy_analytics_disabled');
            cache()->forget('log_verbosity_reduced');
            cache()->forget('background_jobs_disabled');

            app()->instance('analytics.heavy.disabled', false);

            Log::info('Recovered from light degradation');
        }
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

        // 设置 HTTP 头部标识
        $request->headers->set('X-Degraded', 'true');
        $request->headers->set('X-Degradation-Level', (string)$level);
        $request->headers->set('X-Degradation-Mode', 'passthrough');

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
                cache()->tags(['temp', 'analytics'])->flush();
                break;
            case 'aggressive':
                cache()->tags(['temp', 'analytics', 'reports'])->flush();
                break;
            case 'emergency':
                cache()->flush();
                break;
            case 'complete_clear':
                cache()->flush();
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
                break;
        }
    }

    protected function configureObjectPooling(string $mode): void
    {
        switch ($mode) {
            case 'enabled':
                config(['object_pooling.enabled' => true]);
                break;
            case 'strict_reuse':
                config([
                    'object_pooling.enabled' => true,
                    'object_pooling.strict' => true,
                ]);
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
                config(['memory.monitoring_frequency' => 'high']);
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
        config(['cache.default' => $backend]);
    }

    protected function configureSessionStorage(string $storage): void
    {
        switch ($storage) {
            case 'database_fallback':
                config(['session.driver' => 'database']);
                break;
            case 'file_system':
                config(['session.driver' => 'file']);
                break;
            case 'database':
                config(['session.driver' => 'database']);
                break;
            case 'disabled':
                config(['session.driver' => 'array']);
                break;
        }
    }

    protected function configureDataStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'hybrid_caching':
                config(['data.strategy' => 'hybrid']);
                break;
            case 'database_first':
                config(['data.strategy' => 'database_priority']);
                break;
            case 'no_redis_dependency':
                config(['data.redis_dependency' => false]);
                break;
            case 'static_data_only':
                config(['data.strategy' => 'static']);
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
                config(['database.read_preference' => 'replica']);
                break;
            case 'read_only_connections':
                config(['database.write_operations' => false]);
                break;
            case 'minimal_connections':
                config(['database.pool_size' => 1]);
                break;
            case 'health_check_only':
                config(['database.health_check_only' => true]);
                break;
        }
    }

    protected function configureQueryStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'optimized_essential':
                config(['database.query_optimization' => 'essential']);
                break;
            case 'simple_queries_only':
                config(['database.complex_queries' => false]);
                break;
            case 'cache_first_mandatory':
                config(['database.cache_first' => true]);
                break;
            case 'no_database_access':
                config(['database.access' => false]);
                break;
        }
    }

    protected function configureDatabaseCacheStrategy(string $strategy): void
    {
        switch ($strategy) {
            case 'aggressive_query_cache':
                config([
                    'database.query_cache' => true,
                    'database.cache_ttl' => 3600,
                ]);
                break;
            case 'mandatory_caching':
                config(['database.force_cache' => true]);
                break;
            case 'cache_everything_possible':
                config([
                    'database.cache_all' => true,
                    'database.cache_ttl' => 7200,
                ]);
                break;
            case 'static_fallback_only':
                config(['database.fallback' => 'static']);
                break;
        }
    }
}
