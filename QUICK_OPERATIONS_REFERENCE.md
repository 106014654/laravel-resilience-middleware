# 降级策略操作快速参考表

## 🚨 紧急操作速查

### CPU 压力缓解（按优先级）
| 操作 | 作用 | 实施难度 | 效果 | 副作用 |
|------|------|----------|------|--------|
| `disable_heavy_analytics` | 禁用重度分析 | ⭐ | 🔥🔥🔥 | 分析功能暂停 |
| `reduce_log_verbosity` | 降低日志级别 | ⭐ | 🔥🔥 | 调试信息减少 |
| `disable_background_jobs` | 停止后台任务 | ⭐⭐ | 🔥🔥🔥 | 后台处理延迟 |
| `disable_recommendations_engine` | 关闭推荐引擎 | ⭐⭐ | 🔥🔥🔥 | 推荐功能不可用 |
| `disable_real_time_features` | 关闭实时功能 | ⭐⭐⭐ | 🔥🔥🔥🔥 | 实时性丢失 |
| `emergency_cpu_mode` | 紧急CPU模式 | ⭐⭐⭐⭐ | 🔥🔥🔥🔥🔥 | 功能大幅受限 |
| `static_responses_only` | 仅静态响应 | ⭐⭐⭐⭐⭐ | 🔥🔥🔥🔥🔥 | 动态功能全部停用 |

### 内存压力缓解（按优先级）
| 操作 | 作用 | 内存释放量 | 影响范围 |
|------|------|------------|----------|
| `reduce_cache_size_20_percent` | 清理20%缓存 | 🔽🔽 | 临时数据 |
| `force_garbage_collection` | 强制GC | 🔽🔽 | 垃圾对象 |
| `reduce_cache_size_50_percent` | 清理50%缓存 | 🔽🔽🔽 | 非核心缓存 |
| `disable_file_processing` | 禁用文件处理 | 🔽🔽🔽 | 文件功能 |
| `clear_all_non_critical_cache` | 清理非关键缓存 | 🔽🔽🔽🔽 | 非核心数据 |
| `emergency_memory_cleanup` | 紧急内存清理 | 🔽🔽🔽🔽🔽 | 所有缓存 |

### Redis 问题应对（按严重程度）
| Redis状态 | 推荐操作 | 备用方案 |
|-----------|----------|----------|
| 轻度延迟 (50-99ms) | `reduce_redis_operations`<br>`optimize_redis_queries` | 文件缓存后备 |
| 中度延迟 (100-199ms) | `enable_local_cache_fallback`<br>`redis_read_only_mode` | 数据库缓存 |
| 重度延迟 (200ms+) | `complete_redis_bypass`<br>`disable_redis_writes` | 完全切换到文件缓存 |
| 完全不可用 | `complete_redis_bypass` | 文件+数据库缓存 |

### 数据库问题应对（按磁盘使用率）
| 磁盘使用率 | 推荐策略 | 查询策略 | 连接策略 |
|-----------|----------|----------|----------|
| 70-84% | `enable_query_optimization`<br>`cache_frequent_queries` | 优化必要查询 | 正常连接 |
| 85-94% | `prioritize_read_replicas`<br>`disable_complex_queries` | 仅简单查询 | 读副本优先 |
| 95%+ | `enable_read_only_mode`<br>`minimal_database_access` | 禁止数据库访问 | 仅健康检查 |

## ⚡ 性能优化速查

### GC 策略选择
```
normal     → 系统正常时（默认）
increased  → 轻度压力时（每100请求）
aggressive → 中度压力时（每50请求）  
continuous → 重度压力时（每次请求）⚠️性能影响大
```

### 缓存策略选择
```
extend_ttl_50_percent      → 延长TTL减少更新
cache_everything_possible  → 激进缓存所有内容
emergency_static          → 紧急静态缓存
```

### 查询超时调整
```
reduce_20_percent → 轻度数据库压力
reduce_50_percent → 中度数据库压力
minimal (5s)      → 重度数据库压力
```

## 🔄 后备策略速查

### 缓存后备顺序
```
redis → file → database → static
```

### 会话存储后备
```
redis → database → file → array(临时)
```

### 数据获取后备
```
cache → database → static → error
```

## 📊 监控指标阈值

### CPU 使用率阈值
```
70% → Level 1 (轻度降级)
85% → Level 2 (中度降级)
95% → Level 3 (重度降级)
```

### 内存使用率阈值
```
80% → Level 1 (开始清理)
90% → Level 2 (激进清理)
95% → Level 3 (紧急清理)
```

### Redis 响应时间阈值
```
50ms  → Level 1 (减少操作)
100ms → Level 2 (启用后备)
200ms → Level 3 (完全绕过)
```

### 数据库磁盘使用率阈值
```
70% → Level 1 (查询优化)
85% → Level 2 (读副本优先)
95% → Level 3 (只读模式)
```

## 🛠️ 实施检查清单

### 降级前检查
- [ ] 确认监控数据准确性
- [ ] 检查降级配置是否正确
- [ ] 确认后备服务可用性
- [ ] 通知相关团队

### 降级中监控
- [ ] 实时监控资源使用率变化
- [ ] 观察错误率是否下降
- [ ] 检查用户体验影响
- [ ] 记录降级效果

### 降级后恢复
- [ ] 监控资源使用率稳定性
- [ ] 逐步恢复被禁用功能
- [ ] 验证系统完全恢复
- [ ] 总结降级经验

## 🚨 紧急响应流程

### 1分钟内（立即响应）
1. 确认告警真实性
2. 执行 Actions Layer 操作
3. 通知相关人员

### 5分钟内（深度降级）
1. 应用 Performance Optimizations
2. 执行 Memory Management
3. 评估是否需要进一步降级

### 15分钟内（全面应对）
1. 启用 Fallback Strategies
2. 应用 Database Strategies
3. 制定恢复计划

### 持续监控
1. 每5分钟检查系统状态
2. 根据情况调整降级级别
3. 准备恢复操作

## 📞 联系信息

### 紧急联系人
- 系统管理员：[联系方式]
- 数据库管理员：[联系方式]
- 网络管理员：[联系方式]

### 相关文档
- [系统监控面板](http://monitoring.example.com)
- [降级操作日志](http://logs.example.com)
- [恢复操作手册](./RECOVERY_GUIDE.md)

---
**⚠️ 重要提醒**: 降级操作会影响用户体验，请权衡系统稳定性和功能完整性。