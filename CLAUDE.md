# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目概述

GEOFlow 是一套 GEO（生成式引擎优化）内容工程与多站点分发系统，基于 Laravel 12 + PostgreSQL（pgvector）+ Redis 构建。核心链路：知识库/素材 → AI 生成文章 → 审核发布 → 多站点分发 → 数据分析。

## 关键命令

**构建与开发：**
```bash
composer setup          # 一键初始化（install + key:generate + migrate + npm build）
composer run dev        # 并发启动 serve / queue:listen / pail / npm dev
composer test           # 清除配置缓存后运行全部测试
```

**Docker 开发：**
```bash
docker compose build && docker compose up -d
# 前台: http://localhost:18080  后台: /geo_admin/login
```

**测试（使用 PHPUnit，非 Pest）：**
```bash
php artisan test --compact                                    # 全部测试
php artisan test --compact tests/Feature/FooTest.php          # 单文件
php artisan test --compact --filter=testName                  # 单个测试
```
测试使用 SQLite 内存数据库（`:memory:`），见 `phpunit.xml`。

**代码格式化（每次改 PHP 文件后必须执行）：**
```bash
vendor/bin/pint --dirty --format agent    # 仅格式化改动文件
```

**前端：**
```bash
npm run dev     # Vite HMR 开发服务器
npm run build   # 生产构建
```

**Artisan 快捷命令：**
```bash
php artisan geoflow:schedule-tasks              # 调度任务（每分钟由 cron 触发）
php artisan geoflow:process-url-import          # 处理 URL 导入任务
php artisan geoflow:admin-unlock <username>     # 解锁被锁定的管理员
```

## 架构概览

### 三层路由

| 层级 | 路由文件 | 前缀 | 中间件 |
|------|---------|------|--------|
| 公开站点 | `routes/web.php`（site.* 路由） | `/` | `site.locale`, `site.view_log` |
| 管理后台 | `routes/web.php`（admin 路由） | `/geo_admin`（`ADMIN_BASE_PATH`） | `admin.auth`, `admin.activity`, `admin.super` |
| REST API | `routes/api.php` | `/api/v1` | `api.request_id`, `api.auth`, `api.scope` |

### 核心目录

| 目录 | 用途 |
|------|------|
| `app/Services/GeoFlow/` | 核心业务逻辑（27 个服务类），是系统最核心的目录 |
| `app/Http/Controllers/Admin/` | 后台 24 个控制器 |
| `app/Http/Controllers/Api/V1/` | REST API 控制器 |
| `app/Http/Controllers/Site/` | 前台站点控制器 |
| `app/Jobs/` | 队列任务（`ProcessGeoFlowTaskJob`, `ProcessArticleDistributionJob`） |
| `app/Models/` | 32 个 Eloquent 模型 |
| `config/geoflow.php` | GEOFlow 业务配置（站点、上传、缓存、安全等） |
| `resources/views/theme/` | 前台主题模板（4 套主题） |
| `tests/` | 21 个 Feature 测试 + 18 个 Unit 测试 |

### 核心服务职责

- `WorkerExecutionService` — AI 生成文章的执行引擎
- `KnowledgeChunkSyncService` — 知识库切片与向量化（pgvector）
- `TaskLifecycleService` — 任务状态管理
- `ArticleGeoFlowService` — 文章生命周期
- `DistributionOrchestrator` — 分发流程协调
- `DistributionTargetSitePackageBuilder` — 生成目标站点 Agent 包

### 队列与调度

- 调度器每分钟运行 `geoflow:schedule-tasks`，扫描并入队可执行任务
- 队列 Worker 消费 `geoflow`, `distribution`, `default` 三个队列
- 生产环境推荐使用 Horizon 替代 `queue:work`

## 开发规范

**Laravel Boost 规则：** 开发 Laravel/PHP/Tailwind/Horizon/AI SDK 相关代码前，先阅读 `.boost/guidelines.md`（包含完整的编码规范、包版本和最佳实践）。

**技能激活：** `.claude/skills/` 下有 4 个技能，在对应领域工作时必须激活：
- `ai-sdk-development` — AI SDK 相关开发
- `laravel-best-practices` — Laravel PHP 代码编写与审查
- `configuring-horizon` — Horizon 配置
- `tailwindcss-development` — Tailwind CSS 开发

**MCP 工具：** 项目配置了 Laravel Boost MCP 服务器（`php artisan boost:mcp`），提供 `database-query`、`database-schema`、`search-docs` 等工具，优先使用这些工具而非手动命令。

**代码风格：**
- 使用 `php artisan make:` 创建新文件
- 遵循兄弟文件的命名和结构惯例
- 每次改动必须有对应测试
- 改 PHP 文件后运行 `vendor/bin/pint --dirty --format agent`

**数据库：** 生产使用 PostgreSQL + pgvector（向量嵌入），测试使用 SQLite 内存。修改列的迁移必须包含该列之前的所有属性，否则会丢失。

**关键环境变量：**
- `ADMIN_BASE_PATH` — 后台路径前缀（默认 `geo_admin`）
- `GEOFLOW_PUBLIC_LOCALE` — 前台 locale（默认 `zh_CN`）
- `GEOFLOW_DEFAULT_THEME` — 默认前台主题
- `GEOFLOW_HTTP_PROXY` — 出站 HTTP 代理
- `GEOFLOW_EMBEDDING_BATCH_SIZE` — Embedding 向量化批大小（默认 1，最大 64）

## 部署

- **开发：** `docker compose build && docker compose up -d`
- **生产：** 使用 `docker-compose.prod.yml`（Nginx + PHP-FPM），参考 `deploy-scripts/geoflow-docker-deploy.sh`
- **升级：** `git pull → docker compose build → docker compose up -d`
