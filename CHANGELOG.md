# Changelog

All notable changes to `onelap/laravel-resilience-middleware` will be documented in this file.

## [1.0.0] - 2024-01-XX

### Added
- 🚦 **限流中间件**
  - 固定窗口限流算法
  - 滑动窗口限流算法  
  - 令牌桶限流算法
  - 根据系统压力自动调整限流参数
  - Redis 和内存双重备用方案

- 🔄 **熔断器中间件**
  - 三状态熔断器（关闭/开启/半开）
  - 自动故障检测和恢复
  - 响应时间监控
  - 成功率统计

- ⬇️ **服务降级中间件**
  - 双模式降级（阻塞/透传）
  - 多级降级策略（1-3级）
  - 根据系统压力自动触发
  - 自定义降级响应模板

- 📊 **系统监控服务**
  - CPU 使用率监控（Windows/Linux 兼容）
  - 内存使用率监控
  - Redis 连接和内存监控
  - MySQL/PostgreSQL 数据库监控
  - 系统压力等级评估

- 🎯 **智能保护**
  - 基于系统压力的动态调整
  - 多种监控指标综合评估
  - 自动备降和恢复机制

- 🔧 **易用性**
  - Laravel 自动发现支持
  - 丰富的配置选项
  - 环境变量配置支持
  - 中间件组合预设
  - 完整的示例路由

- 📖 **文档和测试**
  - 详细的 README 文档
  - 完整的示例代码
  - PHPUnit 测试覆盖
  - 安装脚本

### Dependencies
- Laravel 5.5+
- PHP 7.1+
- Redis 3.0+ (可选，有内存备用方案)

### Configuration
- 支持 `.env` 环境变量配置
- 详细的配置文件 `config/resilience.php`
- 可配置的监控阈值和降级策略

### Performance
- 最小化性能开销
- Redis 缓存优化
- 内存备用方案确保可用性
- 异步监控数据收集