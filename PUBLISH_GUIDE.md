# 发布到 Packagist 详细指南

## 前置条件

1. **GitHub 账号**：需要有 GitHub 账号
2. **Packagist 账号**：在 [packagist.org](https://packagist.org) 注册账号
3. **Composer 包**：确保本地包结构完整

## 步骤 1：创建 GitHub 仓库

### 1.1 在 GitHub 创建新仓库

```bash
# 访问 GitHub 创建新仓库
# 仓库名建议：laravel-resilience-middleware
# 描述：Laravel application resilience middleware with rate limiting, circuit breaker, and service degradation
# 选择 Public（公开仓库）
# 不要初始化 README（我们已经有了）
```

### 1.2 将本地代码推送到 GitHub

```bash
# 在包目录中执行
cd d:\software\phpstudy_pro\WWW\onelap_web\packages\laravel-resilience-middleware

# 初始化 Git（如果还没有）
git init

# 添加远程仓库（替换为你的 GitHub 用户名）
git remote add origin https://github.com/YOUR_USERNAME/laravel-resilience-middleware.git

# 添加所有文件
git add .

# 创建初始提交
git commit -m "feat: 初始版本 Laravel Resilience Middleware v1.0.0

- 限流中间件支持多种算法（固定窗口、滑动窗口、令牌桶）
- 熔断器中间件实现三状态保护机制
- 服务降级中间件支持双模式降级
- 系统监控服务支持跨平台监控
- 完整的配置系统和示例代码"

# 创建版本标签
git tag -a v1.0.0 -m "Release version 1.0.0"

# 推送到 GitHub
git push -u origin main
git push origin --tags
```

## 步骤 2：在 Packagist 注册包

### 2.1 登录 Packagist

1. 访问 [packagist.org](https://packagist.org)
2. 点击右上角 "Log in"
3. 使用 GitHub 账号登录（推荐）

### 2.2 提交包

1. 登录后点击 "Submit"
2. 在 "Repository URL" 中输入：
   ```
   https://github.com/YOUR_USERNAME/laravel-resilience-middleware
   ```
3. 点击 "Check"
4. Packagist 会验证仓库和 composer.json
5. 如果验证通过，点击 "Submit"

## 步骤 3：配置自动更新

### 3.1 设置 GitHub Webhook（推荐）

1. 在 GitHub 仓库页面，进入 Settings → Webhooks
2. 点击 "Add webhook"
3. 配置 Webhook：
   - **Payload URL**: `https://packagist.org/api/github?username=YOUR_PACKAGIST_USERNAME`
   - **Content type**: `application/json`
   - **Secret**: 在 Packagist 个人资料页面获取 API Token
   - **Events**: 选择 "Just the push event"
4. 点击 "Add webhook"

### 3.2 获取 Packagist API Token

1. 登录 Packagist
2. 进入个人资料页面
3. 在 "API Token" 部分生成新 token
4. 将 token 作为 GitHub Webhook 的 secret

## 步骤 4：验证发布

### 4.1 检查包页面

访问你的包页面：
```
https://packagist.org/packages/onelap/laravel-resilience-middleware
```

### 4.2 测试安装

在新项目中测试安装：
```bash
composer require onelap/laravel-resilience-middleware
```

## 注意事项

### composer.json 要点

确保你的 `composer.json` 包含：

```json
{
    "name": "onelap/laravel-resilience-middleware",
    "type": "library",
    "description": "Laravel application resilience middleware package",
    "keywords": ["laravel", "middleware", "resilience", "rate-limiting", "circuit-breaker"],
    "license": "MIT",
    "authors": [
        {
            "name": "OneLap Team",
            "email": "your-email@example.com"
        }
    ],
    "minimum-stability": "stable",
    "require": {
        "php": "^7.1|^8.0",
        "illuminate/support": "^5.5|^6.0|^7.0|^8.0|^9.0|^10.0"
    }
}
```

### 版本管理

- 使用语义化版本：`v1.0.0`, `v1.0.1`, `v1.1.0` 等
- 每次更新都要打新标签
- 遵循 [Semantic Versioning](https://semver.org/)

### 文档要求

- README.md 必须详细完整
- 包含安装和使用示例
- 说明系统要求和兼容性

## 常见问题

### Q: 包名冲突怎么办？
A: 使用你的 GitHub 用户名作为 vendor 名，如：`yourname/laravel-resilience-middleware`

### Q: 如何更新包？
A: 推送新代码到 GitHub，打新标签，Packagist 会自动同步

### Q: 包没有出现在搜索结果中？
A: 等待几分钟让 Packagist 索引，或者手动触发更新

### Q: 如何设置包的分类标签？
A: 在 composer.json 中使用 `keywords` 字段

## 发布清单

- [ ] GitHub 仓库创建完成
- [ ] 代码推送到 GitHub
- [ ] 版本标签创建
- [ ] Packagist 账号注册
- [ ] 包提交到 Packagist
- [ ] Webhook 配置完成
- [ ] 测试安装验证

完成这些步骤后，你的包就可以通过 `composer require onelap/laravel-resilience-middleware` 安装了！