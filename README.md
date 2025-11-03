# Laravel Resilience Middleware

一个为 Laravel 应用提供全面韧性保护的中间件包，包含限流、熔断器、服务降级等功能。

## 特性

- 🚦 **多种限流策略**：固定窗口、滑动窗口、令牌桶算法
- 🔄 **熔断器模式**：自动故障检测和恢复机制  
- ⬇️ **服务降级**：双模式降级（阻塞/透传）
- 📊 **系统监控**：CPU、内存、Redis、数据库监控
- 🎯 **智能调节**：根据系统压力自动调整保护策略
- 🔧 **易于配置**：丰富的配置选项和默认值
- 🚀 **高性能**：最小化性能开销，支持 Redis 和内存备用方案

## 安装

通过 Composer 安装：

```bash
composer require onelap/laravel-resilience-middleware
```

发布配置文件：

```bash
php artisan vendor:publish --provider="OneLap\LaravelResilienceMiddleware\ResilienceMiddlewareServiceProvider" --tag="resilience-config"
```

## 快速开始

### 1. 基础使用

```php
// 在路由中使用限流
Route::get('/api/users', 'UserController@index')
    ->middleware('resilience.rate-limit:sliding_window,60,1');

// 使用熔断器保护服务
Route::get('/api/orders', 'OrderController@index')
    ->middleware('resilience.circuit-breaker:order-service,5,60,3');

// 使用服务降级
Route::get('/api/recommendations', 'RecommendationController@index')
    ->middleware('resilience.service-degradation:2:block');
```

### 2. 组合中间件

```php
// 轻量级保护：仅限流
Route::middleware('resilience.light')->group(function () {
    Route::get('/api/public/data', 'PublicController@data');
});

// 完整保护：限流 + 熔断器 + 降级
Route::middleware('resilience.full')->group(function () {
    Route::get('/api/critical/payment', 'PaymentController@process');
});
```

## 详细配置

### 限流中间件

```php
// 参数格式：strategy,maxAttempts,decayMinutes
'resilience.rate-limit:sliding_window,100,1'  // 滑动窗口，每分钟100次
'resilience.rate-limit:fixed_window,50,1'     // 固定窗口，每分钟50次  
'resilience.rate-limit:token_bucket,30,1'     // 令牌桶，每分钟30个令牌
```

**支持的策略：**
- `sliding_window`: 滑动窗口（推荐）
- `fixed_window`: 固定窗口
- `token_bucket`: 令牌桶

### 熔断器中间件

```php
// 参数格式：service,failureThreshold,recoveryTimeout,successThreshold
'resilience.circuit-breaker:payment-service,5,60,3'
```

**参数说明：**
- `service`: 服务名称
- `failureThreshold`: 失败次数阈值（默认：5）
- `recoveryTimeout`: 恢复超时时间，秒（默认：60）
- `successThreshold`: 半开状态成功次数阈值（默认：3）

### 服务降级中间件

```php
// 参数格式：degradationLevel:mode
'resilience.service-degradation:1:passthrough'  // 1级降级，透传模式
'resilience.service-degradation:3:block'        // 3级降级，阻塞模式
```

**降级级别：**
- `1`: 轻度降级 - 返回缓存数据
- `2`: 中度降级 - 返回简化数据  
- `3`: 重度降级 - 返回默认响应

**降级模式：**
- `block`: 阻塞模式 - 直接返回降级响应
- `passthrough`: 透传模式 - 设置降级上下文，继续执行

## 系统监控

### 获取系统状态

```php
use OneLap\LaravelResilienceMiddleware\Facades\SystemMonitor;

// 获取系统压力级别
$pressure = SystemMonitor::getSystemPressureLevel(); // low/medium/high/critical

// 获取CPU使用率
$cpuUsage = SystemMonitor::getCpuUsage();

// 获取内存使用率
$memoryUsage = SystemMonitor::getMemoryUsage();
```

### 配置监控阈值

```php
// config/resilience.php
'system_monitor' => [
    'cpu' => [
        'thresholds' => [
            'medium' => 70.0,
            'high' => 85.0, 
            'critical' => 95.0,
        ],
    ],
    'memory' => [
        'thresholds' => [
            'medium' => 70.0,
            'high' => 85.0,
            'critical' => 95.0,
        ],
    ],
],
```

## 环境变量配置

在 `.env` 文件中添加配置：

```env
# 限流配置
RESILIENCE_RATE_LIMIT_STRATEGY=sliding_window
RESILIENCE_RATE_LIMIT_ATTEMPTS=60
RESILIENCE_RATE_LIMIT_DECAY=1

# 熔断器配置
RESILIENCE_CB_FAILURE_THRESHOLD=5
RESILIENCE_CB_RECOVERY_TIMEOUT=60
RESILIENCE_CB_SUCCESS_THRESHOLD=3

# 降级配置
RESILIENCE_DEGRADATION_MODE=block

# 监控配置
RESILIENCE_CPU_MONITOR=true
RESILIENCE_MEMORY_MONITOR=true
RESILIENCE_REDIS_MONITOR=true
RESILIENCE_DB_MONITOR=true

# 系统压力阈值
RESILIENCE_CPU_MEDIUM=70.0
RESILIENCE_CPU_HIGH=85.0
RESILIENCE_CPU_CRITICAL=95.0
```

## 高级用法

### 自定义降级逻辑

在透传模式下，你可以检查降级上下文：

```php
public function index(Request $request)
{
    $degradationLevel = $request->header('X-Degradation-Level');
    $degradationMode = $request->header('X-Degradation-Mode');
    
    if ($degradationLevel) {
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

### 监控熔断器状态

```php
use OneLap\LaravelResilienceMiddleware\Facades\CircuitBreaker;

// 获取所有服务的熔断器统计
$stats = CircuitBreaker::getCircuitStats();

// 获取特定服务的统计
$paymentStats = CircuitBreaker::getCircuitStats('payment-service');
```

## 性能优化

### Redis 配置

为了最佳性能，推荐使用 Redis 作为缓存存储：

```php
// config/cache.php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
],
```

### 内存备用方案

当 Redis 不可用时，中间件会自动切换到内存备用方案，确保服务的可用性。

## 测试

包含完整的测试路由，安装后可以访问：

```bash
# 发布示例路由
php artisan vendor:publish --provider="OneLap\LaravelResilienceMiddleware\ResilienceMiddlewareServiceProvider" --tag="resilience-examples"

# 在 RouteServiceProvider 中加载示例路由
Route::middleware('web')->group(base_path('routes/resilience-examples.php'));
```

测试端点：
- `/api/basic-rate-limit` - 基础限流测试
- `/api/circuit-breaker-basic` - 熔断器测试
- `/api/degradation-block` - 阻塞降级测试
- `/system/status` - 系统状态监控

## 兼容性

- Laravel 5.5+
- PHP 7.1+
- Redis 3.0+ (可选)

## 支持

如果你发现任何问题或需要帮助，请：

1. 查看 [示例路由](examples/routes.php) 了解完整用法
2. 检查配置文件 `config/resilience.php`
3. 提交 Issue 到 GitHub 仓库

## 更新日志

### v1.0.0
- 初始版本发布
- 支持限流、熔断器、服务降级
- 完整的系统监控功能
- Laravel 自动发现支持