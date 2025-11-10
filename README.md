# Laravel Resilience Middleware

[![Latest Version on Packagist](https://img.shields.io/packagist/v/onelap/laravel-resilience-middleware.svg?style=flat-square)](https://packagist.org/packages/onelap/laravel-resilience-middleware)
[![Total Downloads](https://img.shields.io/packagist/dt/onelap/laravel-resilience-middleware.svg?style=flat-square)](https://packagist.org/packages/onelap/laravel-resilience-middleware)
[![PHP Version](https://img.shields.io/packagist/php-v/onelap/laravel-resilience-middleware.svg?style=flat-square)](https://packagist.org/packages/onelap/laravel-resilience-middleware)
[![Laravel Version](https://img.shields.io/badge/Laravel-5.5%2B-orange.svg?style=flat-square)](https://laravel.com)

一个为 Laravel 应用提供企业级韧性保护的中间件包，专为微服务架构和高并发场景设计。

## ✨ 特性亮点

### 🚦 **智能限流系统**
- **多算法支持**：固定窗口、滑动窗口、令牌桶
- **独立资源监控**：CPU、内存、Redis、MySQL 单独触发
- **自适应调整**：根据资源使用率实时调整限流策略
- **分布式友好**：支持 Redis 集群和单机模式

### 🔄 **熔断器保护**
- **三状态管理**：关闭 → 开启 → 半开状态循环
- **智能故障检测**：响应时间和错误率双重监控
- **渐进式恢复**：避免服务雪崩效应
- **资源感知**：根据系统压力动态调整熔断参数

### ⬇️ **五层降级架构**
- **Actions Layer**：立即响应措施（毫秒级生效）
- **Performance Layer**：性能优化策略
- **Memory Layer**：内存管理优化
- **Fallback Layer**：后备策略切换
- **Database Layer**：数据库访问优化

### 📊 **全方位系统监控**
- **本地资源监控**：CPU、内存实时监控
- **远程服务监控**：Redis、MySQL 状态感知
- **健康状态评估**：多维度系统压力计算
- **故障自动恢复**：渐进式恢复机制

### 🎯 **企业级特性**
- **配置灵活**：支持环境变量和动态配置
- **日志完整**：详细的操作日志和指标收集
- **文档齐全**：完整的操作手册和故障排除指南
- **测试覆盖**：包含完整的单元测试和集成测试

## 📦 安装

### 1. 通过 Composer 安装

```bash
composer require onelap/laravel-resilience-middleware
```

### 2. 发布配置文件

```bash
# 发布主配置文件
php artisan vendor:publish --provider="OneLap\LaravelResilienceMiddleware\ResilienceMiddlewareServiceProvider" --tag="resilience-config"

# 发布示例配置和路由（可选）
php artisan vendor:publish --provider="OneLap\LaravelResilienceMiddleware\ResilienceMiddlewareServiceProvider" --tag="resilience-examples"
```

### 3. 注册中间件

在 `app/Http/Kernel.php` 中注册中间件：

```php
protected $middlewareAliases = [
    // ... 其他中间件
    
    // 韧性中间件 - 单独使用
    'rate.limit' => \OneLap\LaravelResilienceMiddleware\Middleware\RateLimitingMiddleware::class,
    'circuit.breaker' => \OneLap\LaravelResilienceMiddleware\Middleware\CircuitBreakerMiddleware::class,
    'service.degradation' => \OneLap\LaravelResilienceMiddleware\Middleware\ServiceDegradationMiddleware::class,
];

protected $middlewareGroups = [
    // 韧性中间件组合 - 推荐使用
    'resilience' => [
        \OneLap\LaravelResilienceMiddleware\Middleware\RateLimitingMiddleware::class,
        \OneLap\LaravelResilienceMiddleware\Middleware\CircuitBreakerMiddleware::class,
        \OneLap\LaravelResilienceMiddleware\Middleware\ServiceDegradationMiddleware::class,
    ],
    
    // 轻量级保护
    'resilience.light' => [
        \OneLap\LaravelResilienceMiddleware\Middleware\RateLimitingMiddleware::class,
    ],
    
    // 核心保护
    'resilience.core' => [
        \OneLap\LaravelResilienceMiddleware\Middleware\RateLimitingMiddleware::class,
        \OneLap\LaravelResilienceMiddleware\Middleware\ServiceDegradationMiddleware::class,
    ],
];
```

### 4. 环境配置

在 `.env` 文件中添加基础配置：

```env
# 启用韧性中间件
RESILIENCE_RATE_LIMIT_ENABLED=true
RESILIENCE_CIRCUIT_BREAKER_ENABLED=true
RESILIENCE_DEGRADATION_ENABLED=true

# 监控配置
RESILIENCE_CPU_MONITOR=true
RESILIENCE_MEMORY_MONITOR=true
RESILIENCE_REDIS_MONITOR=true
RESILIENCE_DB_MONITOR=true

# 系统阈值（可选，有默认值）
RESILIENCE_CPU_HIGH=85.0
RESILIENCE_MEMORY_HIGH=85.0
```

### 5. 验证配置

使用内置命令验证配置是否正确加载：

```bash
# 检查配置状态
php artisan resilience:config-status
```

此命令会显示：
- ✅ 配置文件发布状态
- ✅ 配置加载来源（用户配置 vs 默认配置）
- ✅ 环境变量设置情况
- ✅ 关键配置项验证
- 💡 配置优化建议

**重要说明**：为确保配置生效，请务必：

1. **发布配置文件**：`php artisan vendor:publish --tag=resilience-config`
2. **清理缓存**：`php artisan config:clear`
3. **验证配置**：`php artisan resilience:config-status`

> 💡 **配置加载机制**：系统会智能检测用户是否发布了配置文件，优先使用用户配置，缺失的配置项会自动使用默认值补充。

## 🚀 快速开始

### 1. 基础保护（推荐新手）

最简单的方式是使用预配置的中间件组：

```php
// 完整保护 - 适用于关键业务接口
Route::middleware('resilience')->group(function () {
    Route::post('/api/payment', 'PaymentController@process');
    Route::get('/api/orders', 'OrderController@index');
});

// 轻量级保护 - 适用于一般API
Route::middleware('resilience.light')->group(function () {
    Route::get('/api/users', 'UserController@index');
    Route::get('/api/products', 'ProductController@index');
});
```

### 2. 单个中间件使用

```php
// 仅使用限流保护
Route::get('/api/public/data', 'DataController@index')
    ->middleware('rate.limit');

// 仅使用熔断器保护
Route::get('/api/external-service', 'ExternalController@proxy')
    ->middleware('circuit.breaker:external-api');

// 仅使用服务降级
Route::get('/api/recommendations', 'RecommendationController@index')
    ->middleware('service.degradation:auto');
```

### 3. 自定义参数配置

```php
// 自定义限流参数：滑动窗口，每分钟100次
Route::get('/api/search', 'SearchController@index')
    ->middleware('rate.limit:sliding_window,100,1');

// 自定义熔断器参数：失败5次后熔断60秒
Route::get('/api/payment', 'PaymentController@gateway')
    ->middleware('circuit.breaker:payment-gateway');

// 自定义降级模式：透传模式，在控制器中检查降级状态
Route::get('/api/complex-data', 'ComplexController@index')
    ->middleware('service.degradation:passthrough');
```

### 4. 在控制器中处理降级

当使用透传模式时，可以在控制器中检查降级状态：

```php
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // 检查是否处于降级状态
        $isDegraded = $request->attributes->get('degraded', false);
        $degradationLevel = $request->attributes->get('degradation_level', 0);
        
        if ($isDegraded) {
            return $this->handleDegradedRequest($degradationLevel);
        }
        
        return $this->getFullProductData();
    }
    
    private function handleDegradedRequest($level)
    {
        switch ($level) {
            case 1:
                // 轻度降级：返回缓存数据
                return cache()->remember('products.basic', 300, function () {
                    return $this->getBasicProductData();
                });
                
            case 2:
                // 中度降级：返回简化数据
                return $this->getSimplifiedProductData();
                
            case 3:
                // 重度降级：返回最小数据
                return response()->json([
                    'message' => '服务临时简化，请稍后重试',
                    'data' => []
                ]);
                
            default:
                return $this->getFullProductData();
        }
    }
}
```

## ⚙️ 详细配置

### 配置文件结构

配置文件 `config/resilience.php` 采用分层配置结构：

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Resilience Middleware Configuration
    |--------------------------------------------------------------------------
    |
    | 这里配置韧性中间件的各项参数，包括系统监控、限流、熔断和降级策略
    | 采用独立资源监控模式，每个资源根据自身状态独立执行相应的韧性策略
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 系统监控配置
    |--------------------------------------------------------------------------
    | 监控本地和远程服务的资源使用情况
    */

    'system_monitor' => [
        // 连接超时配置
        'redis_connection_timeout' => env('RESILIENCE_REDIS_CONNECTION_TIMEOUT', 2),
        'mysql_connection_timeout' => env('RESILIENCE_MYSQL_CONNECTION_TIMEOUT', 3),
        /*
        /*
        | Redis 监控配置（可能为远程服务器）
        */
        'redis' => [
            'connection' => env('RESILIENCE_REDIS_CONNECTION', 'default'), // Redis连接名称
        ],

        /*
        | 数据库监控配置（可能为远程服务器）
        */
        'database' => [
            'connection' => env('RESILIENCE_DB_CONNECTION', 'mysql'), // 数据库连接名称
        ],
        /*
        | 监控和日志配置
        */
        'monitoring' => [
            'enable_detailed_logging' => env('RESILIENCE_RL_DETAILED_LOG', true), // 是否启用详细日志
            'log_rate_limit_hits' => env('RESILIENCE_RL_LOG_HITS', true),         // 是否记录限流命中事件
            'log_allowed_requests' => env('RESILIENCE_RL_LOG_ALLOWED', false),     // 是否记录允许通过的请求
            'metrics_collection' => env('RESILIENCE_RL_METRICS', true),           // 是否收集限流指标
        ],


    ],

    /*
    |--------------------------------------------------------------------------
    | 限流中间件配置
    |--------------------------------------------------------------------------
    | 基于系统资源使用情况的自适应限流策略
    */
    'rate_limiting' => [
        /*
        | 基础配置
        */
        'enabled' => env('RESILIENCE_RATE_LIMITING_ENABLED', true),


        // 单项资源阈值限流策略（独立资源监控模式）
        'resource_thresholds' => [
            'cpu' => [
                70 => 0.9,    // CPU 70%时，限流到90%
                80 => 0.7,    // CPU 80%时，限流到70%
                90 => 0.4,    // CPU 90%时，限流到40%
                95 => 0.2,    // CPU 95%时，限流到20%
            ],
            'memory' => [
                70 => 0.9,    // 内存 70%时，限流到90%
                80 => 0.7,    // 内存 80%时，限流到70%
                90 => 0.4,    // 内存 90%时，限流到40%
                95 => 0.2,    // 内存 95%时，限流到20%
            ],
            'redis' => [
                70 => 0.8,    // Redis更敏感，70%时就限流到80%
                80 => 0.6,    // Redis 80%时，限流到60%
                90 => 0.3,    // Redis 90%时，限流到30%
                95 => 0.1,    // Redis 95%时，限流到10%
            ],
            'mysql' => [
                70 => 0.8,    // 数据库也更敏感，70%时限流到80%
                80 => 0.6,    // MySQL 80%时，限流到60%
                90 => 0.3,    // MySQL 90%时，限流到30%
                95 => 0.1,    // MySQL 95%时，限流到10%
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 熔断器中间件配置
    |--------------------------------------------------------------------------
    | 基于滑动窗口的智能熔断器，提供更精确的失败率统计和熔断控制
    | 
    | 工作原理：
    | 1. 使用滑动时间窗口记录所有请求（成功/失败）
    | 2. 实时计算窗口内的失败率
    | 3. 当失败率超过阈值且请求数满足最小要求时触发熔断
    | 4. 熔断后进入恢复周期，逐步恢复服务
    */
    'circuit_breaker' => [
        /*
        | 响应时间阈值配置
        */
        'max_response_time' => env('RESILIENCE_CB_MAX_RESPONSE_TIME', 5000), // 最大响应时间（毫秒），超过视为失败
        /*
        | 熔断器状态配置
        */
        'recovery_timeout' => env('RESILIENCE_CB_RECOVERY_TIMEOUT', 60),     // 熔断后的恢复等待时间（秒）
        'success_threshold' => env('RESILIENCE_CB_SUCCESS_THRESHOLD', 3),    // 半开状态下的成功阈值，连续成功此数量后关闭熔断器
        'window_size' => env('RESILIENCE_CB_WINDOW_SIZE', 60),           // 滑动窗口大小（秒）
        'min_request_count' => env('RESILIENCE_CB_MIN_REQUESTS', 10),    // 最小请求数，低于此数不触发熔断
        'failure_threshold' => env('RESILIENCE_CB_FAILURE_THRESHOLD', 50), // 失败率阈值（百分比），如50表示50%

    ],

    /*
    |--------------------------------------------------------------------------
    | 服务降级中间件配置
    |--------------------------------------------------------------------------
    | 当系统资源压力过大时，自动降级服务功能以保护核心业务
    | 采用分级降级策略：轻度→中度→重度，逐步减少系统负载
    */
    'service_degradation' => [
        // 基础配置
        'enabled' => env('RESILIENCE_DEGRADATION_ENABLED', true),

        /*
        | 监控和日志配置
        */
        'monitoring' => [
            'enable_detailed_logging' => env('RESILIENCE_SD_DETAILED_LOG', false), // 是否启用详细日志
            'log_degradation_events' => env('RESILIENCE_SD_LOG_EVENTS', true),     // 是否记录降级事件
            'log_recovery_events' => env('RESILIENCE_SD_LOG_RECOVERY', true),      // 是否记录恢复事件  
            'log_strategy_execution' => env('RESILIENCE_SD_LOG_STRATEGY', false),  // 是否记录策略执行详情
            'log_resource_monitoring' => env('RESILIENCE_SD_LOG_RESOURCE', false), // 是否记录资源监控数据
            'metrics_collection' => env('RESILIENCE_SD_METRICS', true),           // 是否收集降级指标
        ],

        /*
        |--------------------------------------------------------------------------
        | 降级级别定义
        |--------------------------------------------------------------------------
        | 1级：轻度降级 - 关闭非核心功能，使用缓存数据
        | 2级：中度降级 - 关闭大部分增值功能，简化响应数据 
        | 3级：重度降级 - 仅保留核心功能，返回静态响应
        | 4级：紧急降级 - 系统保护模式，拒绝非关键请求
        */
        'levels' => [
            1 => [
                'name' => 'light_degradation',
                'description' => '轻度降级：关闭非核心功能，优先使用缓存',
                'response_template' => [
                    'success' => true,
                    'message' => '服务运行正常，部分功能临时优化中',
                ],
                'http_status' => 200,
                'cache_headers' => ['Cache-Control' => 'public, max-age=300'],
            ],

            2 => [
                'name' => 'moderate_degradation',
                'description' => '中度降级：大幅简化功能，返回基础数据',
                'response_template' => [
                    'success' => true,
                    'message' => '服务负载较高，已切换到简化模式',
                ],
                'http_status' => 200,
                'cache_headers' => ['Cache-Control' => 'public, max-age=600'],
            ],

            3 => [
                'name' => 'heavy_degradation',
                'description' => '重度降级：仅保留核心功能，静态响应',
                'response_template' => [
                    'success' => false,
                    'message' => '系统繁忙，请稍后重试',
                ],
                'http_status' => 503,
                'cache_headers' => ['Cache-Control' => 'no-cache', 'Retry-After' => '300'],
            ],

            4 => [
                'name' => 'emergency_degradation',
                'description' => '紧急降级：系统保护模式，拒绝非关键请求',
                'response_template' => [
                    'success' => false,
                    'message' => '系统维护中，请稍后访问',
                ],
                'http_status' => 503,
                'cache_headers' => ['Cache-Control' => 'no-cache', 'Retry-After' => '600'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | 资源阈值与降级级别映射
        |--------------------------------------------------------------------------
        | 定义各项资源使用率对应的降级级别
        | 数值表示资源使用率百分比，触发相应的降级级别
        */
        'resource_thresholds' => [
            'cpu' => [
                70 => 1,  // CPU 70% 触发1级降级
                80 => 2,  // CPU 80% 触发2级降级  
                90 => 3,  // CPU 90% 触发3级降级
                95 => 4,  // CPU 95% 触发4级降级（紧急）
            ],
            'memory' => [
                75 => 1,  // 内存 75% 触发1级降级（内存更敏感）
                85 => 2,  // 内存 85% 触发2级降级
                92 => 3,  // 内存 92% 触发3级降级
                96 => 4,  // 内存 96% 触发4级降级（紧急）
            ],
            'redis' => [
                70 => 1,  // Redis 70% 触发1级降级
                80 => 2,  // Redis 80% 触发2级降级
                90 => 3,  // Redis 90% 触发3级降级
                95 => 4,  // Redis 95% 触发4级降级
            ],
            'mysql' => [
                70 => 1,  // MySQL 70% 触发1级降级
                80 => 2,  // MySQL 80% 触发2级降级  
                90 => 3,  // MySQL 90% 触发3级降级
                95 => 4,  // MySQL 95% 触发4级降级
            ],
        ],


        'strategies' => [
            // CPU 降级策略
            'cpu' => [
                70 => [
                    'level' => 1,
                    'actions' => [
                        'disable_heavy_analytics',    // 禁用重度分析功能，释放CPU资源
                        'reduce_log_verbosity',       // 降低日志详细程度，减少I/O操作
                        'reject_non_essential_requests',    // 拒绝非必要请求

                        'reduce_cache_size_20_percent',  // 随机清理指定百分比的临时缓存,优先清理 `temp`, `analytics`, `reports` 标签的缓存

                        'disable_file_processing', // 禁用文件处理功能

                        'reject_large_requests', // 拒绝大型请求

                        'reduce_redis_operations', // 减少redis 操作

                        'redis_read_only_mode', // redis只读模式

                        'complete_redis_bypass', // 完全弃用redis
                    ],
                    'performance_optimizations' => [],
                    'memory_management' => [
                        'cache_cleanup' => 'non_essential', // 清理非必要缓存 'temp', 'analytics' 标签

                    ],
                    'fallback_strategies' => [],
                    'database_strategies' => [
                        'query_strategy' => 'no_database_access', // 数据库查询不可用
                        'cache_strategy' => 'mandatory_caching', // 强制缓存所有查询
                    ]
                ],
            ],

            // Memory 内存降级策略
            'memory' => [],

            // Redis 降级策略
            'redis' => [],

            // Database 数据库降级策略  
            'mysql' => []
        ],

        /*
        |--------------------------------------------------------------------------
        | 恢复策略配置
        |--------------------------------------------------------------------------
        | 定义系统资源压力减轻后的恢复策略
        | 
        | gradual_recovery: 是否启用渐进式恢复，逐步从高级别降级恢复到正常状态
        | recovery_step_interval: 恢复步骤间隔时间，避免频繁切换
        | recovery_threshold_buffer: 恢复阈值缓冲区，防止在临界值附近频繁切换
        | max_recovery_attempts: 最大恢复尝试次数，防止无限重试
        */
        'recovery' => [
            'gradual_recovery' => true,              // 启用渐进式恢复，避免瞬间切换造成系统震荡
            'recovery_step_interval' => 30,          // 恢复步骤间隔30秒，给系统稳定时间
            'recovery_threshold_buffer' => 5,        // 恢复阈值缓冲5%，如70%降级需65%才恢复
            'max_recovery_attempts' => 3,            // 最大恢复尝试3次，防止异常情况下的无限重试
            'recovery_validation_time' => 120,       // 恢复验证时间120秒，确保系统稳定后才完全恢复
        ],

        /*
        |--------------------------------------------------------------------------
        | 非必要请求路径配置
        |--------------------------------------------------------------------------
        | 当系统压力过大时，这些路径的请求会被标记为非必要请求可能被拒绝
        */
        'non_essential_paths' => [],

    ],
];

```

### 限流中间件参数

支持动态参数配置，格式：`strategy,maxAttempts,decayMinutes`

```php
// 滑动窗口策略（推荐）- 平滑限流
Route::middleware('rate.limit:sliding_window,100,1')->group(function () {
    // 每分钟最多100次请求
});

// 固定窗口策略 - 简单高效
Route::middleware('rate.limit:fixed_window,50,1')->group(function () {
    // 每分钟重置，最多50次请求
});

// 令牌桶策略 - 允许突发流量
Route::middleware('rate.limit:token_bucket,30,1')->group(function () {
    // 每分钟30个令牌，可突发处理
});
```

**策略对比：**

| 策略 | 优势 | 适用场景 |
|------|------|----------|
| `sliding_window` | 平滑限流，避免突发 | 需要稳定流量控制 |
| `fixed_window` | 性能最好，逻辑简单 | 一般API限流 |
| `token_bucket` | 允许短时突发流量 | 需要处理流量波动 |

### 熔断器参数

熔断器采用三状态模式：关闭→打开→半开

```php

Route::middleware('circuit.breaker:user-service')->group(function () {
    // 使用配置文件中的默认阈值
});
```

**状态说明：**
- **关闭状态**: 正常处理请求，统计失败次数
- **打开状态**: 直接返回错误，不调用后端服务  
- **半开状态**: 允许少量请求测试服务是否恢复

### 服务降级参数

支持自动和手动两种降级模式：

```php
// 自动降级 - 根据系统压力自动调整
Route::middleware('service.degradation:auto')->group(function () {
    // 系统会根据CPU、内存等指标自动降级
});

// 手动降级 - 指定降级级别和处理方式
Route::middleware('service.degradation:2:passthrough')->group(function () {
    // 强制2级降级，在控制器中处理
});

// 阻塞模式 - 直接返回降级响应
Route::middleware('service.degradation:3:block')->group(function () {
    // 3级降级，直接返回预设响应
});
```

**五层降级架构：**

| 级别 | 名称 | 处理方式 | 适用场景 |
|------|------|----------|----------|
| 1 | Actions | 禁用非核心功能 | CPU使用率偏高 |
| 2 | Performance | 降低响应质量 | 内存使用率偏高 |
| 3 | Memory | 减少内存占用 | 系统资源紧张 |
| 4 | Fallback | 返回默认数据 | 依赖服务异常 |
| 5 | Database | 禁用数据库查询 | 数据库压力过大 |

**降级模式：**
- `block`: 阻塞模式 - 直接返回降级响应
- `passthrough`: 透传模式 - 设置降级上下文，继续执行

## 🔍 系统监控

### 独立资源监控

本中间件采用独立资源监控架构，分别监控CPU、内存、Redis和MySQL的使用情况，特别适合分布式系统：

```php
use OneLap\LaravelResilienceMiddleware\Facades\SystemMonitor;

// 获取各项资源使用率
$cpuUsage = SystemMonitor::getCpuUsage();           // CPU使用率
$memoryUsage = SystemMonitor::getMemoryUsage();     // 内存使用率
$redisUsage = SystemMonitor::getRedisUsage();       // Redis连接/内存使用率
$mysqlUsage = SystemMonitor::getMysqlUsage();       // MySQL连接使用率
```


## 💡 高级用法

### 降级上下文处理

中间件会设置降级上下文信息，支持两种获取方式：

```php
public function index(Request $request)
{
    // 方式1：通过请求属性获取（推荐）
    $isDegraded = $request->attributes->get('degraded', false);
    $degradationLevel = $request->attributes->get('degradation_level', 0);
    $systemPressure = $request->attributes->get('system_pressure', 'low');
    
    // 方式2：通过请求头获取
    $degradationLevel = $request->header('X-Degradation-Level');
    $degradationMode = $request->header('X-Degradation-Mode');
    $systemPressure = $request->header('X-System-Pressure');
    
    if ($isDegraded) {
        // 根据降级级别返回不同数据
        switch ($degradationLevel) {
            case 1:
                return $this->getCachedData();
            case 2:
                return $this->getSimplifiedData();
            case 3:
                return $this->getMinimalData();
        }
    }
    
    return $this->getFullData();
}
```

### 自定义中间件组

创建适合业务场景的中间件组合：

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    // 轻量级API保护
    'api.light' => [
        'throttle:api',
        'rate.limit:sliding_window,100,1',
    ],
    
    // 标准API保护
    'api.standard' => [
        'throttle:api', 
        'rate.limit:sliding_window,60,1',
        'circuit.breaker:api-service',
    ],
    
    // 关键业务保护
    'api.critical' => [
        'throttle:api',
        'rate.limit:token_bucket,30,1',
        'circuit.breaker:critical-service,3,120,5',
        'service.degradation:auto',
    ],
];
```

## 兼容性

- Laravel 5.5+
- PHP 7.1+
- Redis 3.0+ (可选)

