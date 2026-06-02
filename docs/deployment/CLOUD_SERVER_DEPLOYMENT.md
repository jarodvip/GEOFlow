# GEOFlow 云服务器部署记录

本文记录一次基于新云服务器的 GEOFlow 生产部署流程，适用于「Docker Compose 生产栈 + Caddy 自动 HTTPS」场景。

> 本文不记录后台密码、数据库密码、Redis 密码等敏感信息。实际值保存在服务器 `/opt/geoflow/.env.prod` 中。

## 部署概况

| 项目 | 值 |
|------|----|
| 服务器系统 | Debian GNU/Linux 12 (bookworm) |
| 服务器规格 | 2 vCPU / 约 4 GB RAM / 40 GB 系统盘 |
| 部署目录 | `/opt/geoflow` |
| Git 分支 | `main` |
| 对外域名 | `https://geo.asd.icu` |
| 后台地址 | `https://geo.asd.icu/geo_admin/login` |
| HTTPS | Caddy 自动申请 Let’s Encrypt 证书 |
| Web 反代 | Caddy → `127.0.0.1:18080` |
| Reverb 反代 | Caddy `/app/*`, `/apps/*` → `127.0.0.1:18081` |

## 前置条件

1. 域名 A 记录已指向服务器公网 IP。
2. 云服务器安全组已开放 `22`、`80`、`443`。
3. 本机已配置 SSH 免密登录，便于远程部署：

```bash
ssh-copy-id -p 22 root@<server-ip>
ssh -p 22 root@<server-ip> 'whoami && hostname'
```

## 服务器基础环境

新服务器缺少 Git、Docker 和 Caddy 时，先安装基础依赖与 Docker：

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl git openssl gnupg lsb-release

mkdir -p /etc/apt/sources.list.d /etc/apt/keyrings
curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
sh /tmp/get-docker.sh
rm -f /tmp/get-docker.sh

systemctl enable --now docker
docker --version
docker compose version
git --version
```

如果 Docker 官方安装脚本报错：

```text
cannot create /etc/apt/sources.list.d/docker.list: Directory nonexistent
```

先创建目录后重试：

```bash
mkdir -p /etc/apt/sources.list.d /etc/apt/keyrings
```

## 克隆代码与生产配置

```bash
APP_DIR=/opt/geoflow
REPO_URL=https://github.com/yaojingang/GEOFlow.git
BRANCH=main

git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
cd "$APP_DIR"
cp -n .env.prod.example .env.prod
chmod 600 .env.prod
```

关键生产配置：

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://geo.asd.icu
SITE_URL=${APP_URL}
TRUSTED_PROXIES=*
BOOST_BROWSER_LOGS_WATCHER=false
ADMIN_BASE_PATH=geo_admin

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=geo_flow
DB_USERNAME=geo_user
DB_PASSWORD=<strong-random-password>

REDIS_HOST=redis
REDIS_PASSWORD=<strong-random-password>

# 只绑定本机，避免绕过 Caddy 直接公网访问容器端口。
WEB_PORT=127.0.0.1:18080
REVERB_EXPOSE_PORT=127.0.0.1:18081

REVERB_HOST=geo.asd.icu
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_BROADCAST_HOST=reverb
REVERB_BROADCAST_PORT=8080
REVERB_BROADCAST_SCHEME=http
REVERB_APP_SECRET=<strong-random-secret>

GEOFLOW_ADMIN_USERNAME=admin
GEOFLOW_ADMIN_EMAIL=admin@asd.icu
GEOFLOW_ADMIN_PASSWORD=<initial-admin-password>

AUTO_MIGRATE=false
AUTO_SEED=false
AUTO_OPTIMIZE=true
```

随机密钥可用以下命令生成：

```bash
openssl rand -hex 24
```

## 启动 GEOFlow 生产栈

```bash
cd /opt/geoflow
COMPOSE='docker compose --env-file .env.prod -f docker-compose.prod.yml'

$COMPOSE build
$COMPOSE up -d postgres redis
$COMPOSE up init
$COMPOSE up -d app web queue scheduler reverb
$COMPOSE ps
```

正常情况下会启动以下服务：

- `geoflow-postgres-prod`
- `geoflow-redis-prod`
- `geoflow-app-prod`
- `geoflow-web-prod`
- `geoflow-queue-prod`
- `geoflow-scheduler-prod`
- `geoflow-reverb-prod`

## Laravel 写入目录权限

生产栈启动后，如果 `/up` 或首页返回 500，且 Laravel 日志没有写出，优先检查 `www-data` 是否能写入 Laravel 运行期目录：

```bash
cd /opt/geoflow
COMPOSE='docker compose --env-file .env.prod -f docker-compose.prod.yml'

$COMPOSE exec -T -u www-data app sh -lc \
  'touch storage/logs/www-write-test && rm storage/logs/www-write-test'
```

如果出现 `Permission denied`，修复权限：

```bash
$COMPOSE exec -T app chown -R www-data:www-data storage bootstrap/cache
```

然后验证：

```bash
curl -fsS -I --max-time 10 http://127.0.0.1:18080/up
```

应返回 `HTTP/1.1 200 OK`。

## HTTPS 反向代理与 Mixed Content

当 GEOFlow 位于 Caddy、Nginx、CDN 或负载均衡之后时，Laravel 必须信任代理头，生产 Nginx 也必须把上游代理头继续传给 PHP-FPM。否则页面通过 HTTPS 打开时，`asset()` / `url()` 仍可能生成 `http://` 资源地址，浏览器会报 Mixed Content 并阻止 CSS/JS 加载。

本次部署使用两层入口：

```text
浏览器 HTTPS → Caddy :443 → Docker Nginx :18080 → PHP-FPM app:9000 → Laravel
```

关键要求：

1. `.env.prod` 设置：

```env
APP_URL=https://geo.asd.icu
SITE_URL=${APP_URL}
TRUSTED_PROXIES=*
```

2. Laravel 全局启用 `Illuminate\Http\Middleware\TrustProxies`，让 `X-Forwarded-Proto` / `X-Forwarded-Host` 生效。
3. `docker/nginx/default.conf` 不要用容器内 `$scheme` 覆盖上游协议；需要保留 Caddy 传入的 `X-Forwarded-Proto: https`，并为本机健康检查提供 fallback：

```nginx
map $http_x_forwarded_proto $geoflow_forwarded_proto {
    default $http_x_forwarded_proto;
    "" $scheme;
}

map $http_x_forwarded_host $geoflow_forwarded_host {
    default $http_x_forwarded_host;
    "" $host;
}

fastcgi_param HTTP_X_FORWARDED_PROTO $geoflow_forwarded_proto;
fastcgi_param HTTP_X_FORWARDED_HOST $geoflow_forwarded_host;
fastcgi_param HTTP_X_FORWARDED_PREFIX $http_x_forwarded_prefix;
```

验证 Mixed Content 是否修复：

```bash
curl -fsS --max-time 20 https://geo.asd.icu/ \
  | grep -E 'http://geo\.asd\.icu' \
  && echo 'FOUND_INSECURE_URLS' \
  || echo 'NO_INSECURE_URLS'
```

期望输出：

```text
NO_INSECURE_URLS
```

## 安装并配置 Caddy HTTPS

安装 Caddy：

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl gnupg

curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | tee /etc/apt/sources.list.d/caddy-stable.list >/dev/null

apt-get update
apt-get install -y caddy
```

写入 `/etc/caddy/Caddyfile`：

```caddyfile
geo.asd.icu {
    encode gzip zstd

    @reverb path /app/* /apps/*
    reverse_proxy @reverb 127.0.0.1:18081

    reverse_proxy 127.0.0.1:18080 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}
```

加载配置：

```bash
caddy fmt --overwrite /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile
systemctl enable --now caddy
systemctl reload caddy
systemctl status caddy --no-pager --lines=30
```

证书签发成功时，Caddy 日志中会出现类似：

```text
certificate obtained successfully
```

## 生产 Tailwind CSS

生产环境不要加载 `js/tailwindcss.play-cdn.js`。该脚本会在浏览器控制台提示不应在 production 使用。

项目生产视图通过共享局部 `resources/views/partials/tailwind.blade.php` 加载 Tailwind：

- `production`：使用 `@vite('resources/css/app.css')` 输出编译后的 `/build/assets/app-*.css`
- 非 `production`：保留本地 `js/tailwindcss.play-cdn.js`，便于开发调试

生产 Docker 镜像需要在构建阶段执行 Vite：

```bash
npm ci
npm run build
```

并确保以下路径同时存在于 PHP-FPM app 镜像和 Nginx web 镜像中：

```text
public/build/manifest.json
public/build/assets/app-*.css
public/build/assets/app-*.js
```

如果对运行中的生产容器热更新 Blade 或 PHP 文件，注意生产 OPcache 可能不会立刻读取新文件；需要重启相关 PHP-FPM 容器：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml restart app
```

验证生产页面不再加载 Tailwind Play CDN：

```bash
curl -fsS --max-time 20 https://geo.asd.icu/ \
  | grep 'tailwindcss.play-cdn.js' \
  && echo 'FOUND_TAILWIND_PLAY_CDN' \
  || echo 'NO_TAILWIND_PLAY_CDN'
```

期望输出：

```text
NO_TAILWIND_PLAY_CDN
```

同时应能看到编译后的 CSS：

```bash
curl -fsS --max-time 20 https://geo.asd.icu/ \
  | grep -Eo 'https://geo\.asd\.icu/build/assets/[^" ]+\.css' \
  | sort -u
```

## 验证清单

服务器内验证容器状态：

```bash
cd /opt/geoflow
docker compose --env-file .env.prod -f docker-compose.prod.yml ps
```

服务器内验证本机健康端点：

```bash
curl -fsS -I --max-time 15 http://127.0.0.1:18080/up
```

公网验证：

```bash
curl -fsS -I --max-time 20 https://geo.asd.icu/up
curl -fsS -I --max-time 20 https://geo.asd.icu/geo_admin/login
curl -fsS -I --max-time 20 https://geo.asd.icu/
```

数据库迁移状态：

```bash
cd /opt/geoflow
docker compose --env-file .env.prod -f docker-compose.prod.yml exec -T app \
  php artisan migrate:status --no-interaction
```

管理员账号可用性可通过后台登录页手动确认。若需要命令行确认账号存在，可在应用容器中查询 `admins` 表，避免输出密码或哈希。

## 运维命令

进入部署目录：

```bash
ssh -p 22 root@<server-ip>
cd /opt/geoflow
```

查看容器：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml ps
```

查看日志：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml logs -f app web queue scheduler reverb
```

重启应用栈：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
```

更新代码并重建镜像：

```bash
cd /opt/geoflow
git pull
docker compose --env-file .env.prod -f docker-compose.prod.yml build
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
```

查看 Caddy：

```bash
systemctl status caddy --no-pager
journalctl -u caddy -n 100 --no-pager
```

## 注意事项

- 不要把 PostgreSQL 和 Redis 暴露到公网。
- `WEB_PORT=127.0.0.1:18080` 与 `REVERB_EXPOSE_PORT=127.0.0.1:18081` 可以避免绕过 Caddy 访问容器端口。
- 项目自带 `deploy-scripts/geoflow-healthcheck.sh` 当前按纯端口值拼接健康检查 URL；如果 `WEB_PORT` 写成 `127.0.0.1:18080`，脚本会误拼成 `http://127.0.0.1:127.0.0.1:18080/up` 并产生警告。此时以 `curl http://127.0.0.1:18080/up` 和公网 HTTPS 验证为准。
- 上线后立即修改默认管理员密码。
- 更新前备份 `.env.prod`、`storage/` 和 PostgreSQL 数据目录。
