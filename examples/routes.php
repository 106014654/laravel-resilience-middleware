<?php

/**
 * Laravel Resilience Middleware 示例路由
 * 
 * 这个文件展示了如何在路由中使用各种韧性中间件
 * 
 * 安装后，将此文件复制到 routes/resilience-examples.php
 * 并在 RouteServiceProvider 中加载：
 * Route::middleware('web')->group(base_path('routes/resilience-examples.php'));
 */

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 限流中间件示例
|--------------------------------------------------------------------------
*/

// 基础限流：每分钟 60 次请求
Route::get('/api/basic-rate-limit', function () {
    return response()->json([
        'message' => '基础限流测试',
        'timestamp' => now(),
        'data' => ['item1', 'item2', 'item3']
    ]);
})->middleware('resilience.rate-limit:sliding_window,60,1');

// 严格限流：每分钟 10 次请求
Route::get('/api/strict-rate-limit', function () {
    return response()->json([
        'message' => '严格限流测试',
        'timestamp' => now(),
        'requests_per_minute' => 10
    ]);
})->middleware('resilience.rate-limit:fixed_window,10,1');

// 令牌桶限流：每分钟 30 个令牌
Route::get('/api/token-bucket', function () {
    return response()->json([
        'message' => '令牌桶限流测试',
        'algorithm' => 'token_bucket',
        'timestamp' => now()
    ]);
})->middleware('resilience.rate-limit:token_bucket,30,1');

/*
|--------------------------------------------------------------------------
| 熔断器中间件示例
|--------------------------------------------------------------------------
*/

// 基础熔断器：5次失败后熔断，60秒恢复
Route::get('/api/circuit-breaker-basic', function () {
    // 模拟可能失败的服务
    if (rand(1, 10) > 7) {
        abort(500, 'Service temporarily unavailable');
    }

    return response()->json([
        'message' => '熔断器测试成功',
        'service' => 'basic-service',
        'timestamp' => now()
    ]);
})->middleware('resilience.circuit-breaker:basic-service,5,60,3');

// 敏感服务熔断器：3次失败后熔断，30秒恢复
Route::get('/api/circuit-breaker-sensitive', function () {
    // 模拟敏感服务
    if (rand(1, 10) > 8) {
        return response()->json(['error' => 'Service error'], 503);
    }

    return response()->json([
        'message' => '敏感服务正常',
        'service' => 'sensitive-service',
        'timestamp' => now()
    ]);
})->middleware('resilience.circuit-breaker:sensitive-service,3,30,2');

/*
|--------------------------------------------------------------------------
| 服务降级中间件示例
|--------------------------------------------------------------------------
*/

// 阻塞模式降级：系统压力大时直接阻塞请求
Route::get('/api/degradation-block', function () {
    return response()->json([
        'message' => '正常服务响应',
        'data' => [
            'users' => ['Alice', 'Bob', 'Charlie'],
            'features' => ['feature1', 'feature2', 'feature3']
        ],
        'timestamp' => now()
    ]);
})->middleware('resilience.service-degradation:2:block');

// 透传模式降级：系统压力大时降级服务质量
Route::get('/api/degradation-passthrough', function () {
    return response()->json([
        'message' => '正常服务响应',
        'data' => [
            'premium_users' => ['Alice', 'Bob'],
            'premium_features' => ['advanced_analytics', 'priority_support'],
            'recommendations' => ['item1', 'item2', 'item3', 'item4', 'item5']
        ],
        'timestamp' => now()
    ]);
})->middleware('resilience.service-degradation:1:passthrough');

/*
|--------------------------------------------------------------------------
| 组合中间件示例
|--------------------------------------------------------------------------
*/

// 轻量级韧性：仅限流
Route::prefix('light')->middleware('resilience.light')->group(function () {
    Route::get('/users', function () {
        return response()->json(['users' => ['user1', 'user2']]);
    });

    Route::get('/products', function () {
        return response()->json(['products' => ['product1', 'product2']]);
    });
});

// 中等韧性：限流 + 服务降级
Route::prefix('medium')->middleware('resilience.medium')->group(function () {
    Route::get('/dashboard', function () {
        return response()->json([
            'dashboard' => 'full_data',
            'widgets' => ['widget1', 'widget2', 'widget3']
        ]);
    });

    Route::get('/reports', function () {
        return response()->json([
            'reports' => ['monthly', 'weekly', 'daily'],
            'charts' => ['line', 'bar', 'pie']
        ]);
    });
});

// 完整韧性：限流 + 熔断器 + 服务降级
Route::prefix('full')->middleware('resilience.full')->group(function () {
    Route::get('/critical-service', function () {
        // 模拟关键服务
        sleep(1); // 模拟处理时间

        return response()->json([
            'critical_data' => 'important_information',
            'status' => 'success',
            'processing_time' => '1000ms'
        ]);
    });

    Route::get('/payment', function () {
        // 模拟支付服务
        if (rand(1, 100) > 95) {
            abort(500, 'Payment service error');
        }

        return response()->json([
            'payment_status' => 'success',
            'transaction_id' => 'txn_' . uniqid(),
            'amount' => 99.99
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| 系统监控 API 示例
|--------------------------------------------------------------------------
*/

// 系统状态监控
Route::get('/system/status', function () {
    $systemMonitor = app('system-monitor');

    return response()->json([
        'system_pressure' => $systemMonitor->getSystemPressureLevel(),
        'metrics' => $systemMonitor->getSystemMetrics(),
        'healthy' => $systemMonitor->isSystemHealthy(),
        'detailed_status' => $systemMonitor->getDetailedSystemStatus()
    ]);
});

// 熔断器统计
Route::get('/system/circuit-breaker-stats', function () {
    $circuitBreaker = app('circuit.breaker');

    return response()->json([
        'stats' => $circuitBreaker->getCircuitStats(),
        'timestamp' => now()
    ]);
});

/*
|--------------------------------------------------------------------------
| 测试和调试路由
|--------------------------------------------------------------------------
*/

// 模拟高CPU使用率
Route::get('/test/high-cpu', function () {
    $start = microtime(true);

    // 执行CPU密集型操作
    for ($i = 0; $i < 1000000; $i++) {
        md5($i);
    }

    $duration = microtime(true) - $start;

    return response()->json([
        'message' => 'CPU密集型任务完成',
        'duration' => $duration . 's',
        'iterations' => 1000000
    ]);
});

// 模拟内存使用
Route::get('/test/memory-usage', function () {
    $data = [];

    // 分配大量内存
    for ($i = 0; $i < 10000; $i++) {
        $data[] = str_repeat('x', 1024); // 每个元素1KB
    }

    return response()->json([
        'message' => '内存使用测试',
        'allocated_memory' => count($data) . 'KB',
        'current_memory_usage' => memory_get_usage(true) / 1024 / 1024 . 'MB'
    ]);
});

// 模拟慢响应
Route::get('/test/slow-response', function () {
    $delay = request('delay', 2);
    sleep($delay);

    return response()->json([
        'message' => '慢响应测试',
        'delay' => $delay . 's',
        'timestamp' => now()
    ]);
});

// 模拟随机错误
Route::get('/test/random-error', function () {
    $errorRate = request('error_rate', 30); // 默认30%错误率

    if (rand(1, 100) <= $errorRate) {
        $errors = [
            ['code' => 500, 'message' => 'Internal Server Error'],
            ['code' => 503, 'message' => 'Service Unavailable'],
            ['code' => 504, 'message' => 'Gateway Timeout']
        ];

        $error = $errors[array_rand($errors)];
        abort($error['code'], $error['message']);
    }

    return response()->json([
        'message' => '请求成功',
        'error_rate' => $errorRate . '%',
        'timestamp' => now()
    ]);
});
