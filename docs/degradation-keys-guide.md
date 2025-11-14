# 服务降级 Redis Key 使用指南

本文档整理了当前 ServiceDegradationMiddleware 中所有降级相关 Redis key，说明其存储值、触发场景及业务处理方案。

## 1. heavy_analytics_disabled (重度分析禁用)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:heavy_analytics_disabled`
- **存储值**: `true` (禁用时)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: CPU 使用率过高，需要释放 CPU 资源

### 降级处理逻辑
当系统 CPU 使用率超过阈值时，中间件会设置此 key，禁用重度分析功能。

---

## 2. log_verbosity_reduced (日志详细度降低)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:log_verbosity_reduced`
- **存储值**: `true` (降级时)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: I/O 压力过大，需要减少日志写入操作

### 降级处理逻辑
当系统 I/O 使用率超过阈值时，中间件会设置此 key，降低日志详细程度。

---

## 3. file_processing_disabled (文件处理禁用)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:file_processing_disabled`
- **存储值**: `true` (禁用时)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: 内存使用率过高，需要禁用文件处理功能

### 降级处理逻辑
当系统内存使用率超过阈值时，中间件会设置此 key，禁用文件上传和处理功能。

---

## 4. redis_operations_reduced (Redis 操作减少)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:redis_operations_reduced`
- **存储值**: `true` (降级时)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: Redis 连接压力过大，需要减少 Redis 操作

### 降级处理逻辑
当 Redis 使用率超过阈值时，中间件会设置此 key，减少非必要的 Redis 读写操作。

---

## 5. redis_read_only (Redis 只读模式)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:redis_read_only`
- **存储值**: `true` (只读时)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: Redis 写入压力过大，切换为只读模式

### 降级处理逻辑
当 Redis 写入压力过大时，中间件会设置此 key，禁止所有写操作，只允许读取。

---

## 6. redis_bypassed (Redis 完全绕过)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:redis_bypassed`
- **存储值**: `true` (绕过时)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: Redis 服务完全不可用，需要完全绕过 Redis

### 降级处理逻辑
当 Redis 服务完全不可用时，中间件会设置此 key，切换到本地缓存或文件存储。

---

## 7. cache_default (缓存后端切换)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:cache_default`
- **存储值**: `'file'` 或 `'database'` (切换后的缓存后端)
- **TTL**: 10分钟
- **降级类型**: fallback_strategies
- **触发场景**: 缓存服务不可用，需要切换缓存后端

### 降级处理逻辑
当默认缓存服务不可用时，中间件会设置此 key，切换到备用缓存后端。

---

## 8. memory_monitoring_frequency (内存监控频率)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:memory_monitoring_frequency`
- **存储值**: `'high'` (高频监控)
- **TTL**: 10分钟
- **降级类型**: performance_optimizations
- **触发场景**: 系统压力增大，需要提高内存监控频率

### 降级处理逻辑
当系统压力增大时，中间件会设置此 key，提升内存监控的采集频率。

---

## 9. filesystems_uploads_enabled (文件上传开关)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:filesystems_uploads_enabled`
- **存储值**: `false` (禁用上传)
- **TTL**: 10分钟
- **降级类型**: memory_management
- **触发场景**: 内存或磁盘空间不足，需要禁用文件上传

### 降级处理逻辑
当系统内存或磁盘空间不足时，中间件会设置此 key，暂时禁用文件上传功能。

---

## 10. image_processing (图片处理开关)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:image_processing`
- **存储值**: `false` (禁用图片处理)
- **TTL**: 10分钟
- **降级类型**: memory_management
- **触发场景**: 内存使用率过高，需要禁用图片处理功能

### 降级处理逻辑
当系统内存使用率过高时，中间件会设置此 key，禁用图片处理和转换功能。

---

## 11. minimal_object_creation (最小化对象创建)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:minimal_object_creation`
- **存储值**: `true` (启用最小化对象创建)
- **TTL**: 10分钟
- **降级类型**: memory_management
- **触发场景**: 内存压力过大，需要减少对象创建

### 降级处理逻辑
当系统内存压力过大时，中间件会设置此 key，减少不必要的对象创建。

---

## 12. emergency_cpu_mode (CPU 紧急模式)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:emergency_cpu_mode`
- **存储值**: `true` (启用紧急模式)
- **TTL**: 10分钟
- **降级类型**: performance_optimizations
- **触发场景**: CPU 使用率过高，需要启用紧急模式

### 降级处理逻辑
当 CPU 使用率过高时，中间件会设置此 key，启用 CPU 紧急处理模式。

---

## 13. response_minimal (最小化响应)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:response_minimal`
- **存储值**: `true` (启用最小化响应)
- **TTL**: 10分钟
- **降级类型**: performance_optimizations
- **触发场景**: 系统压力过大，需要返回最小化响应

### 降级处理逻辑
当系统压力过大时，中间件会设置此 key，返回最小化的响应内容。

---

## 14. current_degradation_level (当前降级级别)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:current_degradation_level`
- **存储值**: `0-3` (降级级别)
- **TTL**: 30分钟
- **降级类型**: degradation_state
- **触发场景**: 系统自动维护，记录当前降级级别

### 降级处理逻辑
中间件自动维护此 key，记录当前系统的降级级别，用于逐级恢复。

---

## 15. recovery_attempts_count (恢复尝试计数)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:recovery_attempts_count`
- **存储值**: `整数` (恢复尝试次数)
- **TTL**: 1小时
- **降级类型**: degradation_state
- **触发场景**: 恢复流程中自动维护

### 降级处理逻辑
中间件自动维护此 key，记录恢复尝试次数，防止无限恢复尝试。

---

## 16. database_read_only (数据库只读模式)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:database_read_only`
- **存储值**: `true` (只读模式)
- **TTL**: 10分钟
- **降级类型**: database_strategies
- **触发场景**: 数据库写入压力过大，需要切换为只读模式

### 降级处理逻辑
当数据库写入压力过大时，中间件会设置此 key，禁止所有数据库写操作。

---

## 17. database_complex_queries_disabled (复杂查询禁用)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:database_complex_queries_disabled`
- **存储值**: `true` (禁用复杂查询)
- **TTL**: 10分钟
- **降级类型**: database_strategies
- **触发场景**: 数据库查询压力过大，需要禁用复杂查询

### 降级处理逻辑
当数据库查询压力过大时，中间件会设置此 key，禁用复杂查询，只允许简单查询。

---

## 18. cache_aggressive (激进缓存)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:cache_aggressive`
- **存储值**: `true` (启用激进缓存)
- **TTL**: 10分钟
- **降级类型**: performance_optimizations
- **触发场景**: 系统压力过大，需要启用更激进的缓存策略

### 降级处理逻辑
当系统压力过大时，中间件会设置此 key，启用激进缓存策略，缓存更多数据。

---

## 19. gc_forced (强制垃圾回收)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:gc_forced`
- **存储值**: `时间戳` (最后执行时间)
- **TTL**: 1分钟
- **降级类型**: memory_management
- **触发场景**: 内存使用率过高，需要强制垃圾回收

### 降级处理逻辑
当内存使用率过高时，中间件会设置此 key，强制执行垃圾回收操作。

---

## 20. background_jobs_disabled (后台任务禁用)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:background_jobs_disabled`
- **存储值**: `true` (禁用后台任务)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: 系统资源不足，需要暂停后台任务

### 降级处理逻辑
当系统资源不足时，中间件会设置此 key，暂停所有非关键后台任务的执行。

---

## 21. realtime_disabled (实时功能禁用)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:realtime_disabled`
- **存储值**: `true` (禁用实时功能)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: 网络或CPU压力过大，需要禁用实时功能

### 降级处理逻辑
当网络或CPU压力过大时，中间件会设置此 key，禁用WebSocket、推送等实时功能。

---

## 22. websockets_enabled (WebSocket 开关)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:websockets_enabled`
- **存储值**: `false` (禁用 WebSocket)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: 连接数过多或网络压力过大

### 降级处理逻辑
当WebSocket连接数过多或网络压力过大时，中间件会设置此 key，禁用WebSocket连接。

---

## 23. response_static_only (仅静态响应)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:response_static_only`
- **存储值**: `true` (仅返回静态响应)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: 系统压力极大，需要返回预定义的静态响应

### 降级处理逻辑
当系统压力极大时，中间件会设置此 key，跳过动态处理，仅返回预定义的静态内容。

---

## 24. cache_size_reduction (缓存大小减少)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:cache_size_reduction`
- **存储值**: `百分比数值` (如 20.0 表示减少20%)
- **TTL**: 10分钟
- **降级类型**: memory_management
- **触发场景**: 内存不足，需要减少缓存使用量

### 降级处理逻辑
当内存不足时，中间件会设置此 key，按指定百分比清理临时缓存。

---

## 25. object_pooling_enabled (对象池开关)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:object_pooling_enabled`
- **存储值**: `true` (启用对象池)
- **TTL**: 10分钟
- **降级类型**: memory_management
- **触发场景**: 内存压力大，需要启用对象池减少内存分配

---

## 26. degradation_history (降级历史记录)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:degradation_history`
- **存储值**: `JSON数组` (降级历史记录)
- **TTL**: 30分钟
- **降级类型**: degradation_state
- **触发场景**: 记录降级操作历史，用于分析和恢复决策

### 降级处理逻辑
中间件会在每次降级操作时记录历史，包含降级类型、时间、原因等信息，用于后续分析。

---

## 27. health_check_enabled (健康检查开关)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:health_check_enabled`
- **存储值**: `false` (禁用健康检查)
- **TTL**: 5分钟
- **降级类型**: action
- **触发场景**: 系统压力极大，需要减少健康检查频率

### 降级处理逻辑
当系统压力极大时，中间件会设置此 key，暂时禁用或减少健康检查的频率。

---

## 28. api_rate_strict (严格API限流)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:api_rate_strict`
- **存储值**: `true` (启用严格限流)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: API请求量过大，需要启用更严格的限流策略

### 降级处理逻辑
当API请求量过大时，中间件会设置此 key，采用更严格的限流规则，减少并发请求。

---

## 29. session_readonly (会话只读)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:session_readonly`
- **存储值**: `true` (会话只读模式)
- **TTL**: 10分钟
- **降级类型**: action
- **触发场景**: 会话存储压力过大，切换为只读模式

### 降级处理逻辑
当会话存储压力过大时，中间件会设置此 key，禁用会话写入，仅允许读取。

---

## 30. emergency_mode (紧急模式)

### 基本信息
- **Redis Key**: `resilience:IP:PORT:emergency_mode`
- **存储值**: `true` (启用紧急模式)
- **TTL**: 15分钟
- **降级类型**: action
- **触发场景**: 系统出现严重问题，需要进入紧急运行模式

### 降级处理逻辑
当系统出现严重问题时，中间件会设置此 key，启用紧急模式，仅保留核心功能。

---

## 使用指南

### 监控建议
1. **定期检查 Redis 中的这些 key**，了解系统降级状态
2. **监控降级次数**，如果某个降级策略频繁触发，需要优化相应的系统组件
3. **关注恢复尝试次数**，避免系统在降级和恢复之间震荡

### 调试方法
```php
// 获取当前所有降级状态
$degradationKeys = Redis::keys('resilience:' . request()->ip() . ':' . $_SERVER['SERVER_PORT'] . ':*');
foreach ($degradationKeys as $key) {
    $value = Redis::get($key);
    $ttl = Redis::ttl($key);
    echo "Key: {$key}, Value: {$value}, TTL: {$ttl}\n";
}
```

### 手动干预
```php
// 手动清除特定降级状态
Redis::del('resilience:127.0.0.1:8000:database_read_only');

// 手动设置降级状态
Redis::setex('resilience:127.0.0.1:8000:emergency_mode', 900, 'true');
```

---

## 注意事项

1. **所有 key 都包含 IP 和端口前缀**，确保多实例隔离
2. **TTL 时间根据降级严重程度设置**，越严重的降级 TTL 越长
3. **降级类型分类**有助于批量操作和统计分析
4. **Redis 连接失败时**，中间件会使用内存缓存作为降级方案
5. **生产环境中建议监控这些 key 的变化**，及时了解系统状态


