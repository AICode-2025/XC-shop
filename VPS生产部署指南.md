# VPS 生产部署指南（开发文档）

> **版本**：统一版 v1.0（2026-11-08）｜**站点**：https://shop.zwxc.cc ｜**主机**：154.201.81.100（Ubuntu 22.04，宝塔面板）
> 配套：《本地部署指南.md》（本机开发环境）、《独立站建设方案.md》§11 进度台账。
> **安全**：本指南不记录任何口令；数据库口令只存在于服务器 `.env`。

## 1. 生产环境实测

| 项 | 实测值 | 与本地差异 |
|---|---|---|
| 系统 | Ubuntu 22.04 LTS，KVM，2 vCPU / 1963 MB 内存 / 39 GB（可用 24 GB） | 生存在 2G 小机上，可用内存约 600~1100 MB |
| 管控 | 宝塔面板 `14605`，`nginx`/`php-fpm`/`mysqld` 均由宝塔托管 | 本地为独立服务，不涉及面板 |
| Web | Nginx 1.28.3，`/www/server/nginx` | 不使用 `artisan serve` |
| PHP | 8.3.36，`/www/server/php/83`，FPM socket `unix:/tmp/php-cgi-83.sock`，运行用户 `www` | 与本地同版本 |
| PHP 扩展 | 内置 bcmath/curl/dom/gd/intl/mbstring/pdo_mysql/soap/sockets/zip/pcntl/posix；**fileinfo 原缺（已编译补装）**；**opcache 未启用** | 本地有 fileinfo、无 pcntl/posix；生产相反 |
| PHP 限制 | `disable_functions` 禁用 `exec/system/putenv/proc_open/symlink/pcntl_*` 等；`memory_limit=128M` | **CLI 必须加 `-d disable_functions=`** |
| 数据库 | **MySQL 5.7.43**（`/www/server/mysql`），库 `shop_zwxc`、用户 `shop_zwxc` | 本地 8.4.9；`utf8mb4_unicode_ci` 兼容 |
| 缓存/队列 | Redis 6.0.16，`127.0.0.1:6379` 免密 | 本地 database 驱动 |
| Node | v22.23.2（本次未用，前端产物随包上传） | 本地 25.2.1 |
| 站点根 | `/www/wwwroot/shop.zwxc.cc`，Web root 指 `public/`，属主 `www:www` | 同 `public/` |
| 证书 | Let's Encrypt ECC，`/www/server/panel/vhost/cert/shop.zwxc.cc/{fullchain,privkey}.pem` | acme.sh 自动续期 |

## 2. 部署前必读：三处踩坑

1. **`--exclude=vendor` 会误伤 `public/vendor`**：bsdtar 的模式匹配任意层级，导致前端 jQuery/Swiper/Bootstrap 静资源全 404、页面无样式。需单独打包 `public/vendor`（297 文件 / 12.6 MB）。
2. **`fileinfo` 未编译**：`composer install` 直接报 `requires ext-fileinfo`。用宝塔官方脚本补装：
   `bash /www/server/panel/install/fileinfo.sh install 83`（下载源码 + `phpize` 编译，约 3 分钟）。
3. **`disable_functions` 阻断 Composer**：`putenv` 被禁 → `Call to undefined function Composer\XdebugHandler\putenv()`。**只在 CLI 进程内放开**（`php -d disable_functions=`），不改全局 `php.ini`，避免削弱同机其他站点。

## 3. 部署命令序列（可复现）

脚本：`临时文件/产物/20261108-0200-VPS部署-baota.sh`（分步可重跑）、`20261108-0210-VPS部署-tls.sh`（证书与 443 配置）。

| # | 操作 | 出口条件 |
|---|---|---|
| 1 | 本机打包：`tar -czf deploy.tar.gz -C <项目根> --exclude=vendor --exclude=node_modules ...`（排除 `.env`、`临时文件/`、`.git`、`storage/*`、`bootstrap/cache`、`*.md`） | 8.4 MB |
| 2 | `tar -czf public-vendor.tar.gz -C <项目根>/public vendor` 单独打包 | 3.5 MB |
| 3 | `scp` 两个包到 `/tmp/`，解包到 `/www/wwwroot/shop.zwxc.cc` 与 `public/` | `artisan` 存在 |
| 4 | `chown -R www:www`，目录 755 / 文件 644，`storage`+`bootstrap/cache` 775 | 属主正确 |
| 5 | `COMPOSER_ALLOW_SUPERUSER=1 php -d disable_functions= -d memory_limit=-1 /usr/bin/composer install --no-dev --no-scripts` | 150 包、`vendor/autoload.php`、146 MB |
| 6 | 生成 `.env`（生产值）+ `key:generate --force` | `APP_KEY=base64:` 存在 |
| 7 | `artisan migrate --force --graceful` → `db:seed --force` → `touch storage/installed` | **108 张表** |
| 8 | 删除 `config/debugbar.php`（`--no-dev` 未装包，其闭包导致 `config:cache` 失败）→ `storage:link` → `config:cache` + `view:cache` + `route:cache` | 三项缓存成功 |
| 9 | 写 vhost + 伪静态 + `nginx -t && reload` | HTTP 200 |
| 10 | `acme.sh --issue -d shop.zwxc.cc -w public --keylength ec-256` → `--install-cert` → 重写 vhost 为 80 跳转 + 443 | HTTPS 200 |
| 11 | systemd `innoshop-queue`（`queue:work redis`，`Restart=on-failure`+`StartLimitBurst=5`）+ cron `schedule:run` | 服务 active |

生产 `.env` 关键值（`config/` 未改一行）：`APP_ENV=production`、`APP_DEBUG=false`、`APP_URL=https://shop.zwxc.cc`、`LOG_LEVEL=warning`、`DB_*` 指向 `shop_zwxc`、`SESSION/QUEUE/CACHE=redis`、`REDIS_CLIENT=predis`、`DEBUGBAR_ENABLED/SCRIBE_ENABLED/CUSTOMGEO_DEBUG_ROUTE=false`。

## 4. 站点配置要点

- vhost：`/www/server/panel/vhost/nginx/shop.zwxc.cc.conf`（80 段只做 ACME 校验 + 301；443 段承载业务）
- 伪静态：`/www/server/panel/vhost/rewrite/shop.zwxc.cc.conf` → `try_files $uri $uri/ /index.php?$query_string;`
- PHP 接入：`include enable-php-83.conf;`（复用宝塔 socket，不新建 FPM 池）
- 拦截：`.env*`、`.git*`、`composer.*`、`package*.json`、`*.md/.sql/.log`、`/phpinfo.php`、`/nginx.conf`、`storage/logs/` → 404
- 日志：`/www/wwwlogs/shop.zwxc.cc.log`、`.error.log`、`innoshop-queue.log`
- 证书续期：acme.sh 自带 cron（每日 01/07/13/19 点 46 分），LE 建议窗口 2027-01-06

## 4.1 面板接管状态（2026-11-08 12:00 核定）

| 项 | 存储 / 判定依据 | 当前值 |
|---|---|---|
| 站点记录 | `data/db/site.db` → `sites` 表（宝塔 11.5.0 分库，**不是** `data/default.db`） | `id=9`、`name=shop.zwxc.cc`、`path=/www/wwwroot/shop.zwxc.cc`、`project_type=PHP`、`status=1` |
| 域名绑定 | `site.db` → `domain` 表 | `pid=9`、`shop.zwxc.cc:80` |
| PHP 版本识别 | 由 vhost 的 `include enable-php-83.conf` 反推 | `php_version=8.3` |
| SSL 识别 | `data.py::get_site_ssl_info()` 正则取 vhost 的 `ssl_certificate … .pem;` | 已识别：`shop.zwxc.cc`，Let's Encrypt，到期 2027-02-06 |
| 运行目录 | 由 vhost 的 `root` 反推（无独立字段） | `…/shop.zwxc.cc/public` |
| 防跨站 | `.user.ini` | `open_basedir=/www/wwwroot/shop.zwxc.cc/:/tmp/` |
| 列表接口 | `/data?action=getData&table=sites` | 返回 2 条（含本站在内），`共2条` |

**面板识别三要素**：`site.db` 有记录 + vhost 的 `root` 指向 `public` + vhost 含 `ssl_certificate` 行与 `#SSL-START/#SSL-END` 标记。缺任一项，面板会显示"网站目录异常 / 未部署 SSL / PHP 项目不显示"。

## 5. 验收结果（2026-11-08）

| 项 | 结果 |
|---|---|
| `https://shop.zwxc.cc/` | 200，标题/多语言/`build/front` 资源正常 |
| `https://shop.zwxc.cc/panel` → `/panel/login` | 302 → 200 |
| `http://shop.zwxc.cc/` | 301 → HTTPS |
| 静态资源 `/vendor/jquery/*`、`/build/front/css/app.css` | 200 |
| 敏感路径 `.env` / `phpinfo.php` / `nginx.conf` | 404 |
| 数据库 | 108 张表，迁移 + 种子一次通过（MySQL 5.7 无兼容问题）|
| 队列 / 定时 | `innoshop-queue` active；`schedule:run` 每分钟 |
| Laravel 日志 | 仅一条部署前的 sqlite 试探报错，运行期无新增错误 |
| 面板侧识别 | `sites`/`domain` 记录、`php_version=8.3`、SSL 有效期、运行目录 `public` 全部可读；列表接口 `共2条` |

## 6. 运维要点

| 事项 | 操作 |
|---|---|
| **首次登录必改** | `/panel/login`，种子管理员 `admin` / `123456` → 立即改用户名与强密码 |
| 应用日志 | `tail -f storage/logs/laravel-*.log`；`LOG_STACK=daily` |
| 队列 | `systemctl status/restart innoshop-queue`；异常先看 `/www/wwwlogs/innoshop-queue.log` |
| 定时任务 | `crontab -l`；与既有 6 条（acme 续期、`phpfpm-guard.sh` 等）共存，未覆盖 |
| 备份 | 数据库用宝塔「计划任务→备份数据库」或 `mysqldump shop_zwxc`；文件备份 `/www/wwwroot/shop.zwxc.cc`（`vendor`/`node_modules` 可排除） |
| 代码更新（已验证流程） | 本机打包（主包 + `public/vendor` 单独包，均排除 `.env`/`storage/logs`/`vendor`/`node_modules`）→ `scp` 到 `/tmp` → 跑 `20261108-1355-VPS更新-deploy.sh`：先备份到 `/www/backup/innoshop-<时间戳>/`（DB dump + 代码 tar + vhost 副本）→ 解包 → `composer install --no-dev` → `migrate --force` → 三缓存 → 队列重启 → vhost 校验（root 必须 `…/public`）| 
| 更新回滚 | 用备份目录的 `code.tar.gz` 覆盖站点、`db-*.sql.gz` 恢复库、`vhost.conf` 还原配置，再 `optimize:clear` + `reload` |
| 回滚 | 保留上一版打包件 + 数据库备份；回滚 = 覆盖代码 + 恢复库 + 清 `bootstrap/cache` + reload |
| **面板改动后复核** | 任何面板操作（改网站目录/SSL/伪静态/保存配置）后执行 `grep -E 'root\|ssl_certificate' /www/server/panel/vhost/nginx/shop.zwxc.cc.conf`，root 非 `…/public` 或缺少 `ssl_certificate` 时重跑 `20261108-1200-宝塔站点登记-fix-vhost.sh` |
| 面板内改运行目录 | 「网站 → 设置 → 网站目录 → 运行目录」选 `/public` 并保存，等效于手工改 root，可长期由面板维护 |

## 7. 已知遗留与风险

| 项 | 说明 | 处理建议 |
|---|---|---|
| 面板重建 vhost 会覆盖运行目录与 HTTPS | 面板「添加站点」生成 `root=/www/wwwroot/shop.zwxc.cc`（项目根）、无 SSL 的配置，覆盖原 `public` 入口 → HTTPS 000、HTTP 直出源码目录 | 已修复（`20261108-1200-宝塔站点登记-fix-vhost.sh`）；面板改设置后执行 `grep -E 'root|ssl_certificate' vhost` 复核，root 必须是 `…/public` |
| opcache 未启用 | 每次请求重新编译 PHP，性能损失明显 | 宝塔「PHP 83 → 配置修改」启用 opcache；属全局配置，会影响同机 `starauth` 站，需你确认后再动 |
| `memory_limit=128M` | 后台导入/图片处理可能触发内存上限 | 同上，按站点或全局调至 256M |
| `disable_functions` 全局禁用 `exec/proc_open/symlink` | 若后续插件需调外部命令（ImageMagick、备份、采集）会失败 | 届时对 PHP-FPM 放开指定函数，或改为 CLI 队列执行 |
| GeoLite2 城市库未落地 | 仅国家库 `geolite2-country.mmdb` 已上传，IP→城市精度不足 | 按《GeoNames与GeoLite2集成说明》补城市库（需 MaxMind License） |
| 磁盘余量 | 可用 24 GB，`vendor` 146 MB | 图片/日志增长需监控，日志写入 `/www/wwwlogs` |

## 8. 与本地一致性

必须相同：PHP 8.3.x、`composer.lock`/`package-lock.json`、`utf8mb4`、迁移与种子、`config/` 代码、插件/主题扩展方式、前后台入口路径。
只允许值不同：`APP_*`、`DB_*`、`QUEUE/CACHE/SESSION=redis`、`FILESYSTEM_DISK`、`MAIL_*`。
**平台差异**：本地无 `pcntl/posix`（Horizon 未启用）；生产有，但 `config/horizon.php` 未发布，本期队列用 `queue:work` + systemd，Horizon 列为后续项。
