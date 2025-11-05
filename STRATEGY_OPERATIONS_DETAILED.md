# 服务降级策略操作详解

本文档详细说明了 Laravel 弹性中间件的五层降级策略中每一项操作的具体实现和作用机制。

## 目录
- [第1层：Actions Layer (动作层)](#第1层actions-layer-动作层)
- [第2层：Performance Optimizations (性能优化层)](#第2层performance-optimizations-性能优化层)
- [第3层：Memory Management (内存管理层)](#第3层memory-management-内存管理层)
- [第4层：Fallback Strategies (后备策略层)](#第4层fallback-strategies-后备策略层)
- [第5层：Database Strategies (数据库策略层)](#第5层database-strategies-数据库策略层)

---

## 第1层：Actions Layer (动作层)

### 🖥️ CPU 相关操作

#### `disable_heavy_analytics` ⭐⭐⭐
**作用**: 禁用重度分析功能
**实现机制**:
- 在应用实例中设置 `analytics.heavy.disabled` 标志
- 修改配置项 `analytics.heavy_processing` 为 `false`
- 缓存禁用状态 10 分钟
**影响范围**: 所有需要大量CPU计算的分析功能
**适用场景**: CPU使用率超过70%时的首选措施
```php
app()->instance('analytics.heavy.disabled', true);
config(['analytics.heavy_processing' => false]);
cache(['heavy_analytics_disabled' => true], now()->addMinutes(10));
```

#### `reduce_log_verbosity` ⭐⭐⭐
**作用**: 降低日志详细程度
**实现机制**:
- 临时提高日志级别到 `warning`
- 禁用调试模式 (`app.debug = false`)
- 缓存设置状态
**影响范围**: 所有日志输出，减少磁盘I/O
**适用场景**: CPU和I/O压力较大时
```php
config(['logging.level' => 'warning']);
config(['app.debug' => false]);
```

#### `disable_background_jobs`
**作用**: 禁用后台任务
**实现机制**:
- 暂停队列处理
- 增加队列延迟到 1 小时
- 缓存禁用状态
**影响范围**: 所有后台异步任务
**适用场景**: 需要释放CPU和内存资源时
```php
cache(['background_jobs_disabled' => true], now()->addMinutes(10));
config(['queue.connections.default.delay' => 3600]);
```

#### `disable_recommendations_engine`
**作用**: 禁用推荐引擎
**实现机制**:
- 设置应用实例标志 `recommendations.disabled`
- 缓存禁用状态
**影响范围**: 个性化推荐功能
**适用场景**: 电商、内容平台在高并发时的降级
```php
app()->instance('recommendations.disabled', true);
cache(['recommendations_disabled' => true], now()->addMinutes(10));
```

#### `disable_real_time_features`
**作用**: 禁用实时功能
**实现机制**:
- 关闭 WebSocket 连接 (`websockets.enabled = false`)
- 禁用广播功能 (`broadcasting.enabled = false`)
- 关闭实时特性 (`realtime.features = false`)
**影响范围**: 实时通知、聊天、实时数据更新
**适用场景**: 高并发时保护核心功能
```php
config([
    'websockets.enabled' => false,
    'broadcasting.enabled' => false,
    'realtime.features' => false,
]);
```

#### `emergency_cpu_mode`
**作用**: 启用紧急CPU模式
**实现机制**:
- 禁用同步队列 (`queue.sync = false`)
- 切换到数组会话驱动 (避免会话写入)
- 启用紧急CPU模式标志
**影响范围**: 整个应用运行模式
**适用场景**: CPU使用率达到95%以上的紧急情况
```php
config([
    'app.emergency_cpu_mode' => true,
    'queue.sync' => false,
    'session.driver' => 'array',
]);
```

#### `reject_non_essential_requests` ⭐⭐⭐
**作用**: 拒绝非必要请求
**实现机制**:
- 检查请求路径是否在非必要路径列表中
- 设置请求标志 `request.non_essential`
**影响范围**: 分析、报告、上传等非核心功能的请求
**适用场景**: 极高负载时保护核心业务
```php
$nonEssentialPaths = ['api/analytics', 'api/recommendations', 'uploads'];
// 检查并标记非必要请求
```

#### `static_responses_only`
**作用**: 仅返回静态响应
**实现机制**:
- 启用静态响应模式
- 设置缓存策略为静态文件
**影响范围**: 所有动态内容生成
**适用场景**: 系统几乎不可用时的最后手段
```php
config([
    'response.static_only' => true,
    'cache.strategy' => 'static_files',
]);
```

### 🧠 Memory 相关操作

#### `reduce_cache_size_20_percent` / `reduce_cache_size_50_percent`  ⭐⭐⭐
**作用**: 按比例减少缓存大小
**实现机制**:
- 随机清理指定百分比的临时缓存
- 优先清理 `temp`, `analytics`, `reports` 标签的缓存
**影响范围**: 非核心缓存数据
**适用场景**: 内存使用率80%以上时
```php
$tagsToReduce = ['temp', 'analytics', 'reports'];
foreach ($tagsToReduce as $tag) {
    if (rand(1, 100) <= $percentage * 100) {
        cache()->tags($tag)->flush();
    }
}
```

#### `disable_file_processing` ⭐⭐⭐
**作用**: 禁用文件处理功能
**实现机制**:
- 禁用文件上传 (`filesystems.uploads_enabled = false`)
- 禁用图片处理 (`image.processing = false`)
- 禁用文件处理 (`file.processing = false`)
**影响范围**: 文件上传、图片处理、文档转换等
**适用场景**: 内存紧张时减少内存占用大的操作
```php
config([
    'filesystems.uploads_enabled' => false,
    'image.processing' => false,
    'file.processing' => false,
]);
```

#### `emergency_memory_cleanup`
**作用**: 紧急内存清理
**实现机制**:
- 清空所有缓存 (`cache()->flush()`)
- 强制垃圾回收 (`gc_collect_cycles()`)
- 清理视图缓存
- 清理容器中的非必要实例
**影响范围**: 整个应用的内存状态
**适用场景**: 内存使用率95%以上的危急情况
```php
cache()->flush();
gc_collect_cycles();
// 清理各种内存占用
```

#### `reject_large_requests` ⭐⭐⭐
**作用**: 拒绝大型请求
**实现机制**:
- 检查请求的 `content-length` 头
- 当超过 1MB 时设置拒绝标志
**影响范围**: 大文件上传、大数据提交
**适用场景**: 内存紧张时防止大请求消耗过多内存

### 📊 Redis 相关操作

#### `reduce_redis_operations` ⭐⭐⭐
**作用**: 减少Redis操作
**实现机制**:
- 设置 Redis 操作限制为 100
- 缓存减少状态
**影响范围**: 所有Redis读写操作
**适用场景**: Redis响应时间超过50ms时
```php
config(['cache.redis.operations_limit' => 100]);
cache(['redis_operations_reduced' => true], now()->addMinutes(10));
```

#### `enable_local_cache_fallback`
**作用**: 启用本地缓存后备
**实现机制**:
- 设置缓存后备为数组驱动
- 在应用实例中设置本地后备标志
**影响范围**: 缓存读取操作
**适用场景**: Redis不稳定时的应急措施
```php
config(['cache.fallback' => 'array']);
app()->instance('cache.local_fallback', true);
```

#### `redis_read_only_mode` ⭐⭐⭐
**作用**: Redis只读模式
**实现机制**:
- 设置 Redis 只读配置
- 缓存只读状态
**影响范围**: 所有Redis写入操作
**适用场景**: Redis性能下降但仍可读取时
```php
config(['database.redis.read_only' => true]);
cache(['redis_read_only' => true], now()->addMinutes(10));
```

#### `complete_redis_bypass` ⭐⭐⭐
**作用**: 完全跳过Redis
**实现机制**:
- 切换缓存驱动到文件
- 设置 Redis 跳过标志
**影响范围**: 所有依赖Redis的功能
**适用场景**: Redis完全不可用时
```php
config(['cache.default' => 'file']);
app()->instance('redis.bypassed', true);
```

### 💾 Database 相关操作

#### `enable_query_optimization`
**作用**: 启用查询优化
**实现机制**:
- 启用查询缓存
- 设置高级优化等级
**影响范围**: 所有数据库查询
**适用场景**: 数据库压力增大的初期阶段
```php
config([
    'database.default_options' => [
        'query_cache' => true,
        'optimization_level' => 'high',
    ]
]);
```

#### `prioritize_read_replicas`
**作用**: 优先使用读副本
**实现机制**:
- 设置读取偏好为副本
- 如果配置了读写分离，切换到读库
**影响范围**: 数据库读取操作
**适用场景**: 主库压力大但读库正常时
```php
config(['database.read_preference' => 'replica']);
if (config('database.connections.mysql_read')) {
    config(['database.default' => 'mysql_read']);
}
```

#### `enable_read_only_mode`
**作用**: 启用数据库只读模式
**实现机制**:
- 设置数据库只读配置
- 缓存只读状态
**影响范围**: 所有数据库写入操作
**适用场景**: 数据库磁盘空间不足或写入压力过大
```php
config(['database.read_only' => true]);
cache(['database_read_only' => true], now()->addMinutes(10));
```

#### `database_emergency_mode`
**作用**: 数据库紧急模式
**实现机制**:
- 限制连接数为 1
- 设置查询超时为 5 秒
- 启用紧急模式标志
**影响范围**: 所有数据库操作
**适用场景**: 数据库资源极度紧张时
```php
config([
    'database.emergency_mode' => true,
    'database.connection_limit' => 1,
    'database.query_timeout' => 5,
]);
```

---

## 第2层：Performance Optimizations (性能优化层)

### 垃圾回收频率调整 (`gc_frequency`)

#### `normal`
**作用**: 使用默认GC策略
**实现**: 不做特殊处理
**适用场景**: 系统正常运行时

#### `increased`
**作用**: 增加GC频率
**实现**: 每100个请求执行一次GC
**适用场景**: 轻度内存压力时
```php
$requestCount = app()->has('request.count') ? app('request.count') : 0;
if ($requestCount % 100 === 0) {
    gc_collect_cycles();
}
```

#### `aggressive`
**作用**: 激进的GC策略
**实现**: 每50个请求执行一次GC
**适用场景**: 中度内存压力时
```php
if ($requestCount % 50 === 0) {
    gc_collect_cycles();
}
```

#### `continuous`
**作用**: 连续GC
**实现**: 每次请求都执行GC
**适用场景**: 内存压力极大的紧急情况
**注意**: 会显著影响性能，仅在紧急情况下使用
```php
gc_collect_cycles(); // 每次请求都执行
```

### 缓存策略调整 (`cache_strategy`)

#### `extend_ttl_50_percent`
**作用**: 延长缓存TTL 50%
**实现**: 将当前TTL乘以1.5
**适用场景**: 减少缓存更新频率，降低系统负载
```php
$currentTtl = config('cache.default_ttl', 3600);
config(['cache.default_ttl' => $currentTtl * 1.5]);
```

#### `cache_everything_possible`
**作用**: 缓存所有可能的内容
**实现**: 启用激进缓存策略
**适用场景**: 系统压力大时最大化缓存利用率
```php
config([
    'cache.aggressive' => true,
    'cache.ttl' => 7200, // 2小时
    'view.cache' => true,
    'route.cache' => true,
]);
```

#### `emergency_static`
**作用**: 紧急静态缓存
**实现**: 仅使用静态响应
**适用场景**: 系统几乎不可用时
```php
config([
    'response.static_only' => true,
    'cache.everything' => false,
]);
```

### 查询超时调整 (`query_timeout`)

#### `reduce_20_percent`
**作用**: 减少查询超时20%
**实现**: 将当前超时时间乘以0.8
**适用场景**: 轻度数据库压力时
```php
$currentTimeout = config('database.connections.mysql.options.timeout', 30);
config(['database.connections.mysql.options.timeout' => $currentTimeout * 0.8]);
```

#### `reduce_50_percent`
**作用**: 减少查询超时50%
**实现**: 将当前超时时间乘以0.5
**适用场景**: 中度数据库压力时

#### `minimal`
**作用**: 最小超时设置
**实现**: 设置超时为5秒
**适用场景**: 数据库压力极大时
```php
config(['database.connections.mysql.options.timeout' => 5]);
```

### 处理模式调整 (`processing`)

#### `health_check_only`
**作用**: 仅处理健康检查
**实现**: 只允许健康检查相关路由
**适用场景**: 系统几乎不可用时保持基本监控
```php
config([
    'processing.mode' => 'health_check',
    'routes.enabled' => ['health', 'status', 'ping'],
]);
```

---

## 第3层：Memory Management (内存管理层)

### 缓存清理 (`cache_cleanup`)

#### `non_essential` ⭐⭐⭐
**作用**: 清理非必要缓存
**实现**: 清理临时和分析缓存标签
**适用场景**: 轻度内存压力时
```php
cache()->tags(['temp', 'analytics'])->flush();
```

#### `aggressive`
**作用**: 激进缓存清理
**实现**: 清理更多类型的缓存
**适用场景**: 中度内存压力时
```php
cache()->tags(['temp', 'analytics', 'reports'])->flush();
```

#### `emergency`
**作用**: 紧急缓存清理
**实现**: 清空所有缓存
**适用场景**: 内存危急情况
```php
cache()->flush();
```

#### `complete_clear`
**作用**: 完全清理
**实现**: 清空所有缓存并重置OPcache
**适用场景**: 内存溢出的紧急情况
```php
cache()->flush();
if (function_exists('opcache_reset')) {
    opcache_reset();
}
```

### 对象池配置 (`object_pooling`)

#### `enabled`
**作用**: 启用对象池
**实现**: 开启对象池功能
**适用场景**: 优化对象创建和销毁的开销
```php
config(['object_pooling.enabled' => true]);
```

#### `strict_reuse`
**作用**: 严格对象复用
**实现**: 启用严格的对象复用策略
**适用场景**: 内存压力大时强制复用对象
```php
config([
    'object_pooling.enabled' => true,
    'object_pooling.strict' => true,
]);
```

### GC策略配置 (`gc_strategy`)

#### `frequent`
**作用**: 频繁垃圾回收
**实现**: 设置GC概率为100%
**适用场景**: 需要及时回收内存时
```php
ini_set('gc_probability', '100');
```

#### `aggressive`
**作用**: 激进垃圾回收
**实现**: 强制启用GC并设置高概率
**适用场景**: 内存压力较大时
```php
ini_set('gc_probability', '100');
gc_enable();
```

#### `continuous`
**作用**: 持续垃圾回收
**实现**: 注册tick函数持续执行GC
**适用场景**: 内存压力极大的紧急情况
**注意**: 严重影响性能
```php
register_tick_function('gc_collect_cycles');
```

### 内存限制调整 (`memory_limits`)

#### `strict_enforcement`
**作用**: 严格内存限制
**实现**: 限制内存使用到128M
**适用场景**: 防止内存溢出
```php
ini_set('memory_limit', '128M');
```

#### `emergency_limits`
**作用**: 紧急内存限制
**实现**: 限制内存使用到64M
**适用场景**: 内存极度紧张时
```php
ini_set('memory_limit', '64M');
```

### 内存监控配置 (`memory_monitoring`)

#### `increased`
**作用**: 增强内存监控
**实现**: 提高内存监控频率
**适用场景**: 需要密切监控内存使用时
```php
config(['memory.monitoring_frequency' => 'high']);
```

---

## 第4层：Fallback Strategies (后备策略层)

### 缓存后端切换 (`cache_backend`)

#### `file`
**作用**: 切换到文件缓存
**实现**: 设置默认缓存驱动为文件
**适用场景**: Redis不可用时
```php
config(['cache.default' => 'file']);
```

#### `database`
**作用**: 切换到数据库缓存
**实现**: 设置默认缓存驱动为数据库
**适用场景**: Redis和文件系统都有问题时
```php
config(['cache.default' => 'database']);
```

### 会话存储切换 (`session_storage`)

#### `database_fallback`
**作用**: 切换到数据库会话存储
**实现**: 设置会话驱动为数据库
**适用场景**: 默认会话存储不可用时
```php
config(['session.driver' => 'database']);
```

#### `file_system`
**作用**: 切换到文件系统会话
**实现**: 设置会话驱动为文件
**适用场景**: 内存和数据库会话都不可用时
```php
config(['session.driver' => 'file']);
```

#### `disabled`
**作用**: 禁用会话存储
**实现**: 使用数组会话驱动（临时）
**适用场景**: 系统压力极大时
```php
config(['session.driver' => 'array']);
```

### 数据策略调整 (`data_strategy`)

#### `database_first`
**作用**: 数据库优先策略
**实现**: 优先从数据库获取数据
**适用场景**: 缓存不可靠时
```php
config(['data.strategy' => 'database_priority']);
```

#### `no_redis_dependency`
**作用**: 移除Redis依赖
**实现**: 禁用Redis依赖
**适用场景**: Redis完全不可用时
```php
config(['data.redis_dependency' => false]);
```

#### `static_data_only`
**作用**: 仅使用静态数据
**实现**: 切换到静态数据模式
**适用场景**: 动态数据生成消耗过大时
```php
config(['data.strategy' => 'static']);
```

---

## 第5层：Database Strategies (数据库策略层)

### 连接策略 (`connection_strategy`)

#### `read_replica_priority`
**作用**: 读副本优先
**实现**: 设置读取偏好为副本
**适用场景**: 主库压力大时分担读取压力
```php
config(['database.read_preference' => 'replica']);
```

#### `read_only_connections`
**作用**: 只读连接
**实现**: 禁用写操作
**适用场景**: 数据库磁盘空间不足时
```php
config(['database.write_operations' => false]);
```

#### `minimal_connections`
**作用**: 最小连接数
**实现**: 设置连接池大小为1
**适用场景**: 数据库连接资源紧张时
```php
config(['database.pool_size' => 1]);
```

#### `health_check_only`
**作用**: 仅健康检查连接
**实现**: 只允许健康检查相关的数据库访问
**适用场景**: 数据库几乎不可用时
```php
config(['database.health_check_only' => true]);
```

### 查询策略 (`query_strategy`)

#### `optimized_essential`
**作用**: 优化必要查询
**实现**: 只执行经过优化的必要查询
**适用场景**: 数据库性能下降但仍可用时
```php
config(['database.query_optimization' => 'essential']);
```

#### `simple_queries_only`
**作用**: 仅简单查询
**实现**: 禁用复杂查询
**适用场景**: 数据库负载高时
```php
config(['database.complex_queries' => false]);
```

#### `no_database_access` ⭐⭐⭐
**作用**: 无数据库访问
**实现**: 完全禁用数据库访问
**适用场景**: 数据库完全不可用时
```php
config(['database.access' => false]);
```

### 缓存策略 (`cache_strategy`)

#### `aggressive_query_cache`
**作用**: 激进查询缓存
**实现**: 缓存所有查询1小时
**适用场景**: 数据库压力大时减少查询
```php
config([
    'database.query_cache' => true,
    'database.cache_ttl' => 3600,
]);
```

#### `mandatory_caching` ⭐⭐⭐
**作用**: 强制缓存
**实现**: 所有查询都必须缓存
**适用场景**: 数据库性能严重下降时
```php
config(['database.force_cache' => true]);
```

#### `static_fallback_only`
**作用**: 仅静态后备
**实现**: 使用静态数据作为后备
**适用场景**: 数据库和缓存都不可用时
```php
config(['database.fallback' => 'static']);
```

---

## 总结

这五层策略形成了一个完整的降级体系：

1. **Actions Layer**: 快速响应，立即生效
2. **Performance Optimizations**: 系统级调优
3. **Memory Management**: 内存专项优化
4. **Fallback Strategies**: 服务切换后备
5. **Database Strategies**: 数据访问优化

每一层都有其特定的职责和适用场景，通过合理配置可以在各种异常情况下保护系统稳定运行。

## 使用建议

1. **渐进式应用**: 从轻度降级开始，根据情况逐步加重
2. **监控反馈**: 实时监控降级效果，及时调整策略
3. **恢复机制**: 确保有完善的恢复机制
4. **测试验证**: 定期测试各种降级场景
5. **文档维护**: 保持降级策略文档的更新