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
    ->middleware('circuit.breaker:payment-gateway,5,60,3');

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
    // 全局开关
    'enabled' => true,
    
    // 限流配置
    'rate_limiting' => [
        'enabled' => true,
        'default_strategy' => 'sliding_window',
        'default_max_attempts' => 60,
        'default_decay_minutes' => 1,
    ],
    
    // 熔断器配置
    'circuit_breaker' => [
        'enabled' => true,
        'failure_threshold' => 5,
        'recovery_timeout' => 60,
        'success_threshold' => 3,
    ],
    
    // 服务降级配置
    'service_degradation' => [
        'enabled' => true,
        'strategy' => 'auto', // auto, passthrough, block
        'monitor_interval' => 30,
        'levels' => [
            1 => ['name' => 'actions', 'description' => '禁用部分功能'],
            2 => ['name' => 'performance', 'description' => '降低响应质量'],  
            3 => ['name' => 'memory', 'description' => '减少内存使用'],
            4 => ['name' => 'fallback', 'description' => '返回默认数据'],
            5 => ['name' => 'database', 'description' => '禁用数据库查询'],
        ],
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
// 完整参数：service,failureThreshold,recoveryTimeout,successThreshold
Route::middleware('circuit.breaker:payment-api,5,60,3')->group(function () {
    // 失败5次后熔断，60秒后尝试恢复，连续成功3次完全恢复
});

// 使用默认参数
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

