# 资源降级策略执行指南

## 概述

`executeResourceStrategy` 方法是服务降级中间件的核心执行引擎，负责根据系统资源压力情况，分层次地应用各种降级策略。该方法采用五层执行架构，确保降级措施的有序、有效实施。

## 执行架构

### 五层执行模型

```
┌─────────────────────────────────────┐
│        Actions Layer (第1层)        │  ← 即时响应，毫秒级生效
├─────────────────────────────────────┤
│  Performance Optimizations (第2层)  │  ← 系统参数调整
├─────────────────────────────────────┤
│    Memory Management (第3层)        │  ← 内存专项管理
├─────────────────────────────────────┤
│   Fallback Strategies (第4层)       │  ← 备用方案切换
├─────────────────────────────────────┤
│   Database Strategies (第5层)       │  ← 数据库专项优化
└─────────────────────────────────────┘
```

## 详细说明

### 第1层：Actions Layer（动作层）

**目的：** 立即生效的快速响应措施  
**生效时间：** 毫秒级  
**作用范围：** 当前请求及后续请求

#### 支持的动作类型

##### CPU相关动作
- `disable_heavy_analytics` - 禁用重度分析处理
- `reduce_log_verbosity` - 降低日志详细程度  
- `disable_background_jobs` - 暂停后台任务
- `cache_aggressive_mode` - 启用激进缓存模式
- `disable_recommendations_engine` - 禁用推荐引擎
- `disable_real_time_features` - 禁用实时功能
- `minimal_response_processing` - 最小化响应处理
- `force_garbage_collection` - 强制垃圾回收
- `emergency_cpu_mode` - 紧急CPU模式
- `reject_non_essential_requests` - 拒绝非必要请求
- `static_responses_only` - 仅允许静态响应

##### Memory相关动作  
- `reduce_cache_size_20_percent` - 减少缓存20%
- `reduce_cache_size_50_percent` - 减少缓存50%
- `disable_file_processing` - 禁用文件处理
- `minimal_object_creation` - 最小化对象创建
- `emergency_memory_cleanup` - 紧急内存清理
- `clear_all_non_critical_cache` - 清除非关键缓存
- `reject_large_requests` - 拒绝大型请求

##### Redis相关动作
- `reduce_redis_operations` - 减少Redis操作
- `enable_local_cache_fallback` - 启用本地缓存后备
- `optimize_redis_queries` - 优化Redis查询
- `bypass_non_critical_redis` - 跳过非关键Redis操作
- `database_cache_priority` - 数据库缓存优先
- `limit_redis_connections` - 限制Redis连接数
- `redis_read_only_mode` - Redis只读模式
- `complete_database_fallback` - 完全数据库后备
- `disable_redis_writes` - 禁用Redis写入
- `complete_redis_bypass` - 完全跳过Redis

##### Database相关动作
- `enable_query_optimization` - 启用查询优化
- `prioritize_read_replicas` - 优先读副本
- `cache_frequent_queries` - 缓存频繁查询
- `enable_read_only_mode` - 启用只读模式
- `disable_complex_queries` - 禁用复杂查询
- `force_query_caching` - 强制查询缓存
- `database_emergency_mode` - 数据库紧急模式
- `cache_only_responses` - 仅缓存响应
- `minimal_database_access` - 最小化数据库访问
- `complete_database_bypass` - 完全跳过数据库

### 第2层：Performance Optimizations（性能优化层）

**目的：** 调整系统级别的运行参数  
**生效时间：** 秒级  
**作用范围：** 后续请求

#### 支持的优化类型

##### GC频率调整
- `normal` - 默认GC策略
- `increased` - 每100个请求执行GC
- `aggressive` - 每50个请求执行GC  
- `continuous` - 每次请求都执行GC

##### 缓存策略调整
- `extend_ttl_50_percent` - 延长TTL 50%
- `cache_everything_possible` - 缓存所有可能内容
- `static_only` - 仅静态内容
- `emergency_static` - 紧急静态模式

##### 查询超时调整
- `reduce_20_percent` - 减少超时20%
- `reduce_50_percent` - 减少超时50%
- `minimal` - 最小超时时间（5秒）

##### 处理模式调整
- `health_check_only` - 仅健康检查模式

### 第3层：Memory Management（内存管理层）

**目的：** 专门针对内存压力的管理措施  
**生效时间：** 毫秒到秒级  
**作用范围：** 当前进程

#### 支持的管理策略

##### 缓存清理
- `non_essential` - 清理非必要缓存
- `aggressive` - 激进清理
- `emergency` - 紧急全部清理
- `complete_clear` - 完全清理（包括opcache）

##### 对象池配置
- `enabled` - 启用对象池
- `strict_reuse` - 严格复用模式

##### GC策略
- `frequent` - 频繁GC
- `aggressive` - 激进GC
- `continuous` - 持续GC

##### 内存限制
- `strict_enforcement` - 严格限制（128M）
- `emergency_limits` - 紧急限制（64M）

### 第4层：Fallback Strategies（后备策略层）

**目的：** 当主要服务不可用时的备用方案  
**生效时间：** 毫秒级  
**作用范围：** 当前请求及后续请求

#### 支持的后备策略

##### 缓存后端切换
- 支持切换到 `file`、`database`、`array` 等后端

##### 会话存储切换  
- `database_fallback` - 数据库后备
- `file_system` - 文件系统存储
- `database` - 数据库存储
- `disabled` - 禁用会话

##### 数据策略调整
- `hybrid_caching` - 混合缓存
- `database_first` - 数据库优先
- `no_redis_dependency` - 无Redis依赖
- `static_data_only` - 仅静态数据

### 第5层：Database Strategies（数据库策略层）

**目的：** 专门针对数据库访问的优化措施  
**生效时间：** 毫秒级  
**作用范围：** 数据库相关操作

#### 支持的数据库策略

##### 连接策略
- `read_replica_priority` - 读副本优先
- `read_only_connections` - 只读连接
- `minimal_connections` - 最小连接数
- `health_check_only` - 仅健康检查

##### 查询策略  
- `optimized_essential` - 优化必要查询
- `simple_queries_only` - 仅简单查询
- `cache_first_mandatory` - 强制缓存优先
- `no_database_access` - 禁止数据库访问

##### 缓存策略
- `aggressive_query_cache` - 激进查询缓存
- `mandatory_caching` - 强制缓存
- `cache_everything_possible` - 缓存所有可能内容
- `static_fallback_only` - 仅静态后备

## 实际使用场景

### 场景1：CPU使用率过高（85%以上）

**问题：** 系统CPU负载过高，响应变慢  
**解决方案：**

```php
// 配置示例
'cpu' => [
    85 => [
        'level' => 2,
        'actions' => [
            'disable_heavy_analytics',
            'reduce_log_verbosity', 
            'disable_background_jobs'
        ],
        'performance_optimizations' => [
            'gc_frequency' => 'increased',
            'cache_strategy' => 'cache_everything_possible'
        ]
    ]
]
```

**执行效果：**
1. 立即禁用重度分析功能
2. 降低日志级别到warning
3. 暂停所有后台任务
4. 增加GC频率释放内存
5. 启用激进缓存减少计算

### 场景2：内存不足（内存使用率90%以上）

**问题：** 系统内存不足，可能出现OOM  
**解决方案：**

```php
'memory' => [
    90 => [
        'level' => 3,
        'actions' => [
            'emergency_memory_cleanup',
            'reduce_cache_size_50_percent',
            'disable_file_processing'
        ],
        'memory_management' => [
            'cache_cleanup' => 'aggressive',
            'gc_strategy' => 'continuous',
            'memory_limits' => 'strict_enforcement'
        ]
    ]
]
```

**执行效果：**
1. 立即执行紧急内存清理
2. 减少缓存大小50%
3. 禁用文件处理功能
4. 激进清理所有非必要缓存
5. 启用持续垃圾回收
6. 强制限制内存使用

### 场景3：Redis连接异常

**问题：** Redis服务不可用或响应缓慢  
**解决方案：**

```php
'redis' => [
    100 => [  // Redis完全不可用
        'level' => 4,
        'actions' => [
            'complete_redis_bypass',
            'enable_local_cache_fallback'
        ],
        'fallback_strategies' => [
            'cache_backend' => 'file',
            'session_storage' => 'database',
            'data_strategy' => 'database_first'
        ]
    ]
]
```

**执行效果：**
1. 完全跳过Redis操作
2. 启用本地缓存作为后备
3. 切换缓存后端到文件系统
4. 会话存储切换到数据库
5. 数据策略调整为数据库优先

### 场景4：数据库压力过大

**问题：** 数据库响应缓慢或连接数过多  
**解决方案：**

```php
'database' => [
    80 => [
        'level' => 2, 
        'actions' => [
            'enable_read_only_mode',
            'disable_complex_queries',
            'cache_frequent_queries'
        ],
        'database_strategies' => [
            'connection_strategy' => 'read_replica_priority',
            'query_strategy' => 'simple_queries_only',
            'cache_strategy' => 'aggressive_query_cache'
        ]
    ]
]
```

**执行效果：**
1. 启用数据库只读模式
2. 禁用复杂查询操作
3. 缓存频繁查询结果
4. 优先使用读副本
5. 仅允许简单查询
6. 启用激进查询缓存

## 配置最佳实践

### 1. 分级配置

```php
'resource_thresholds' => [
    'cpu' => [
        70 => 1,    // 轻度降级
        85 => 2,    // 中度降级  
        95 => 3,    // 重度降级
    ],
    'memory' => [
        80 => 1,
        90 => 2,
        95 => 3,
    ]
],
```

### 2. 渐进式策略

```php
'strategies' => [
    'cpu' => [
        70 => [
            'level' => 1,
            'actions' => ['disable_heavy_analytics']
        ],
        85 => [
            'level' => 2, 
            'actions' => ['disable_heavy_analytics', 'reduce_log_verbosity'],
            'performance_optimizations' => ['gc_frequency' => 'increased']
        ],
        95 => [
            'level' => 3,
            'actions' => ['emergency_cpu_mode', 'static_responses_only'],
            'performance_optimizations' => ['processing' => 'health_check_only']
        ]
    ]
]
```

### 3. 恢复策略

```php
'recovery' => [
    'gradual_recovery' => true,
    'recovery_threshold_buffer' => 5,
    'recovery_step_interval' => 30
]
```

## 监控和调试

### 日志监控

系统会自动记录详细的执行日志：

```php
// 执行前日志
Log::debug("Executing actions for resource: cpu", [
    'actions' => ['disable_heavy_analytics', 'reduce_log_verbosity'],
    'resource_type' => 'cpu'
]);

// 执行后日志  
Log::info("Resource degradation strategy executed successfully", [
    'resource' => 'cpu',
    'strategy_level' => 2,
    'applied_layers' => [
        'actions' => true,
        'performance_optimizations' => true,
        'memory_management' => false,
        'fallback_strategies' => false, 
        'database_strategies' => false
    ],
    'execution_time' => '2023-11-04 15:30:25',
    'request_path' => '/api/users',
    'request_method' => 'GET'
]);
```

### 性能指标监控

建议监控以下关键指标：

- 降级触发频率
- 各层策略执行时间
- 资源使用率变化
- 请求响应时间变化
- 错误率变化

### 故障排查

1. **检查配置文件** - 确认策略配置正确
2. **查看执行日志** - 确认策略是否正确执行
3. **监控资源指标** - 确认降级效果
4. **测试恢复机制** - 确认能正常恢复

## 扩展开发

### 添加自定义动作

```php
// 在 executeActions 方法中添加
case 'custom_action_name':
    $this->executeCustomAction($request);
    break;

// 实现自定义方法
protected function executeCustomAction(Request $request): void
{
    // 自定义逻辑
    config(['custom.setting' => true]);
    cache(['custom_flag' => true], now()->addMinutes(10));
    
    Log::info('Custom action executed');
}
```

### 添加自定义策略层

可以在 `executeResourceStrategy` 方法中添加新的策略层：

```php
// 添加安全策略层
if (isset($strategy['security_strategies'])) {
    $this->applySecurityStrategies($strategy['security_strategies']);
}
```

## 总结

`executeResourceStrategy` 方法通过五层执行架构，提供了完整、灵活的服务降级解决方案。正确使用该方法需要：

1. **理解执行顺序** - 五层按优先级依次执行
2. **合理配置策略** - 根据实际业务需求配置降级策略
3. **持续监控调优** - 通过日志和指标持续优化策略
4. **测试验证** - 确保降级和恢复机制正常工作

通过合理使用该方法，可以显著提升系统在高负载情况下的稳定性和可用性。