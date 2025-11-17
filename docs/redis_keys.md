# 服务降级 Redis Key（actions 层）一览表（与代码完全对应）

| Key（字段名）                        | 说明/用途                                                                                   | 备注/实现建议 |
|--------------------------------------|---------------------------------------------------------------------------------------------|---------------|
| disable_heavy_analytics              | 禁用重型分析功能                                                                             | 禁用大数据分析、复杂报表等高消耗分析功能，减少系统负载 |
| reduce_log_verbosity                 | 降低日志详细级别                                                                             | 只保留错误/警告日志，关闭调试和详细日志，减少磁盘和IO压力 |
| disable_background_jobs              | 禁用后台任务/队列                                                                            | 暂停异步队列、定时任务、批量处理等后台作业，释放资源 |
| enable_aggressive_caching            | 启用积极缓存策略                                                                             | 提高缓存命中率，延长缓存TTL，减少实时计算和数据库访问 |
| disable_recommendations_engine       | 禁用推荐引擎                                                                                 | 关闭个性化推荐、猜你喜欢等功能，降低算法和数据消耗 |
| disable_realtime_features            | 禁用实时功能                                                                                 | 关闭实时推送、消息通知、在线状态等高频实时服务 |
| enable_minimal_response_processing   | 启用最简响应处理                                                                             | 仅返回必要字段，去除多余数据和装饰，减少响应体积 |
| gc_forced                            | 立即强制垃圾回收                                                                             | 触发PHP垃圾回收，释放内存，适用于内存紧张场景 |
| enable_emergency_cpu_mode            | 启用紧急 CPU 降级模式                                                                        | 降低并发、限制CPU密集型操作、优先处理核心请求 |
| enable_static_responses_only         | 仅返回静态响应，关闭动态内容                                                                 | 只提供静态页面或缓存内容，关闭动态渲染和计算 |
| reduce_cache_size                    | 缩减缓存大小                                                                                 | 主动清理缓存，降低缓存占用比例 |
| disable_file_processing              | 禁用文件处理相关功能                                                                         | 禁止上传、下载、解析等文件相关操作，释放内存和IO |
| enable_minimal_object_creation       | 启用最小对象创建                                                                             | 避免大对象、复杂结构，仅创建必要对象，降低内存占用 |
| enable_large_request_rejection       | 拒绝过大请求                                                                                 | 限制上传/POST体积，超限直接拒绝，防止内存溢出 |
| perform_emergency_memory_cleanup     | 执行紧急内存清理                                                                             | 释放缓存、关闭大对象、主动清理无用数据 |
| reduce_redis_operations              | 降低 Redis 操作频率                                                                          | 合并/延迟写入，减少高频读写，批量操作替代单次操作 |
| enable_local_cache_fallback          | 启用本地缓存回退                                                                             | Redis 不可用时，自动切换为本地缓存，保障可用性 |
| optimize_redis_queries               | 启用 Redis 查询优化                                                                           | 精简查询字段，减少大key操作，避免全表扫描 |
| bypass_non_critical_redis            | 跳过非关键 Redis key                                                                          | 只操作核心key，跳过统计、日志等非关键数据 |
| enable_redis_read_only_mode          | 启用 Redis 只读模式                                                                           | 禁止写入，仅允许读取，防止数据异常或主从切换期间出错 |
| disable_redis_writes                 | 禁用 Redis 写入                                                                              | 只读不写，防止写入压力过大或数据不一致 |
| enable_complete_redis_bypass         | 完全绕过 Redis                                                                               | 关闭所有Redis相关操作，直接走本地或数据库 |
| enable_query_optimization            | 启用数据库查询优化                                                                           | 启用SQL缓存，减少重复查询，提升响应速度 |
| prioritize_read_replicas             | 优先使用数据库读副本                                                                          | 读操作优先走只读库，减轻主库压力 |
| enable_frequent_query_caching        | 启用频繁查询缓存                                                                             | 对高频SQL启用缓存，减少数据库负载 |
| enable_database_read_only_mode       | 启用数据库只读模式                                                                           | 禁止写入，仅允许读取，适用于只读场景或主库异常 |
| disable_complex_queries              | 禁用复杂查询                                                                                 | 禁止多表/聚合/子查询等复杂SQL，防止拖慢数据库 |
| force_query_caching                  | 强制所有查询缓存                                                                             | 所有SQL结果强制缓存，牺牲实时性换取性能 |
| enable_database_emergency_mode       | 启用数据库紧急模式                                                                           | 只允许核心SQL，关闭非必要查询，限制并发 |
| enable_cache_only_responses          | 仅返回缓存响应                                                                               | 只返回缓存数据，数据库不可用时保障服务可用性 |
| enable_minimal_database_access       | 最小化数据库访问                                                                             | 只查核心表/字段，减少无关SQL，降低负载 |
| enable_complete_database_bypass      | 完全绕过数据库                                                                               | 关闭所有数据库操作，仅依赖缓存或静态数据 |
| gc_frequency                       | performance_optimizations  | 垃圾回收频率（normal/increased/aggressive/continuous）                                       | 提高GC频率，适当牺牲性能换取内存释放 |
| cache_strategy                     | performance_optimizations  | 缓存策略（如 static_files、emergency_static、extend_ttl_50_percent 等）                      | 选择更激进的缓存策略，优先静态内容，延长TTL |
| query_timeout                      | performance_optimizations  | 查询超时策略（reduce_20_percent/reduce_50_percent/minimal）                                  | 缩短SQL/接口超时时间，防止慢查询拖垮系统 |
| processing_mode                    | performance_optimizations  | 处理模式（如 health_check_only）                                                             | 只处理健康检查等核心请求，其他请求降级或拒绝 |
| cache_default_ttl_extended         | performance_optimizations  | 默认缓存 TTL 延长                                                                             | 延长缓存有效期，减少频繁失效和重建 |
| cache_ttl                          | performance_optimizations  | 缓存 TTL 设置                                                                                | 统一缩短或延长缓存TTL，动态调整 |
| view_cache                         | performance_optimizations  | 视图缓存开关                                                                                 | 启用页面/模板缓存，减少渲染压力 |
| route_cache                        | performance_optimizations  | 路由缓存开关                                                                                 | 启用路由缓存，减少路由解析消耗 |
| dynamic_content                    | performance_optimizations  | 动态内容开关                                                                                 | 关闭动态内容，仅返回静态或缓存数据 |
| cache_everything                   | performance_optimizations  | 是否缓存所有内容                                                                             | 全量缓存，牺牲实时性换取极致性能 |
| database_timeout                   | performance_optimizations  | 数据库超时时间                                                                               | 降低SQL超时阈值，防止慢SQL拖垮服务 |
| processing_mode                    | performance_optimizations  | 处理模式（如 health_check_only）                                                             | 只处理健康检查等核心请求，其他请求降级或拒绝 |
| cache_size_reduction               | memory_management          | 缓存缩减百分比                                                                               | 主动清理缓存，降低缓存占用比例 |
| filesystems_uploads_enabled        | memory_management          | 文件上传开关                                                                                 | 禁止上传，释放磁盘和内存资源 |
| image_processing                   | memory_management          | 图片处理开关                                                                                 | 禁止图片上传、压缩、缩放等操作 |
| file_processing                    | memory_management          | 文件处理开关                                                                                 | 禁止文件解析、导入导出等操作 |
| object_pooling_enabled             | memory_management          | 对象池开关                                                                                   | 关闭对象池，减少内存占用 |
| minimal_objects                    | memory_management          | 是否最小对象模式                                                                             | 只创建必要对象，避免大对象和复杂结构 |
| request_size_limit                 | memory_management          | 请求体大小限制                                                                               | 限制上传/POST体积，超限直接拒绝 |
| cache_redis_operations_limit       | memory_management/redis    | Redis 操作限制                                                                               | 限制高频操作，合并/延迟写入 |
| cache_redis_compression            | memory_management/redis    | Redis 缓存压缩开关                                                                           | 启用压缩，减少内存占用 |
| database_redis_prefix              | memory_management/redis    | Redis 数据库前缀                                                                             | 只操作指定前缀的key，便于分库分表 |
| cache_fallback                     | fallback_strategies        | 缓存后备方案（如 array、file、database）                                                     | Redis 故障时自动切换为本地/文件/数据库缓存 |
| database_default_read              | database_strategies        | 数据库默认读库（如 mysql_read）                                                              | 读操作优先走只读库，减轻主库压力 |
| database_optimization_level        | database_strategies        | 数据库优化级别（如 high）                                                                    | 启用高性能索引、SQL优化、分区等措施 |
| database_query_cache_ttl           | database_strategies        | 查询缓存 TTL                                                                                 | 延长高频SQL缓存时间，减少数据库压力 |
| complex_queries_disabled           | database_strategies        | 复杂查询禁用标记                                                                             | 禁止多表/聚合/子查询等复杂SQL |
| database_cache_all_queries         | database_strategies        | 所有查询缓存开关                                                                             | 所有SQL结果强制缓存，牺牲实时性换取性能 |
| database_connection_limit          | database_strategies        | 数据库连接数限制                                                                             | 降低最大连接数，防止数据库资源耗尽 |
| database_query_timeout             | database_strategies        | 数据库查询超时时间                                                                           | 缩短SQL超时阈值，防止慢SQL拖垮服务 |
| database_essential_queries_only    | database_strategies        | 仅允许必要查询                                                                               | 只允许核心业务SQL，禁止统计/报表等非核心SQL |
| routes_enabled                     | database_strategies        | 允许的路由列表（如 health、status、ping）                                                    | 只开放健康检查、状态等核心路由，其他路由降级或关闭 |

---

#### 说明
- 所有 key 实际存储时均带有 IP:PORT 前缀（如 `resilience:127.0.0.1:8000:heavy_analytics_disabled`），以实现多实例隔离。
- actions 层为最直接的降级措施，performance_optimizations 层为运行参数调整，memory_management 层为内存相关优化，fallback_strategies 层为后备方案切换，database_strategies 层为数据库访问优化。
- 仅表中 key 为有效，代码与 Redis 存储已完全一致，避免策略失效或混乱。

如需进一步细化某一层的 key 或补充说明，请查阅源码或联系维护者。
