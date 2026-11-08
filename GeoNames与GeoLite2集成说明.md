# GeoNames + MaxMind GeoLite2 集成说明

> **版本**：统一版 v1.0（2026-11-07）｜**适用底座**：InnoShop CE v0.9.2（Laravel 13）｜**集成目录**：`app/CustomGeo/`
> **状态**：代码与文档已落地；迁移/导入/回归测试未执行（本机 `vendor/` 未安装，`php artisan` 不可用），验证方式见 §9。

## 1. 目标与边界

| 目标 | 落点 |
|---|---|
| 用 GeoNames 官方数据替换默认国家/州省，并补充城市级数据 | 扩展原生 `countries` / `states`，新增 `geo_admin2` / `geo_cities` |
| 用官方 GeoLite2 离线库做访客定位 | `app/CustomGeo/MaxMind/*` + 复用项目已有 `geoip2/geoip2` |
| 访客首次进入推荐币种/语言 | `GeoDetectVisitor` 中间件（挂在 `front` 组末尾） |
| 复用原生 zone 机制做税率/运费区域 | 复用 `regions` / `region_states`，提供 `innoshop:geo-zone-create` |

**不做**：删除或重构原生地址/地区模型、硬编码地区清单、在线付费 IP 接口、提交原始数据到 Git。

## 2. 文件清单

### 2.1 新增（全部自定义逻辑集中）

| 路径 | 作用 |
|---|---|
| `config/customgeo.php` | 路径、许可文案、精简国家白名单、币种/语言映射、缓存、调试开关 |
| `app/CustomGeo/CustomGeoServiceProvider.php` | 注册单例、命令、路由、定时任务、前台中间件 |
| `app/CustomGeo/routes.php` | `/customgeo/debug`、`/customgeo/cities` |
| `app/CustomGeo/Support/GeoPaths.php` | 统一路径解析（数据/MMDB/备份/日志） |
| `app/CustomGeo/GeoNames/GeoNamesDownloader.php` | 官方 dump 下载（cURL、`.part` 断点续传、ZIP 校验、解压） |
| `app/CustomGeo/GeoNames/GeoNamesParser.php` | 逐行清洗与分类（国家 / admin1 / admin2 / 城市） |
| `app/CustomGeo/GeoNames/GeoNamesImporter.php` | 导入编排（全量/精简、断点续导、进度、错误日志） |
| `app/CustomGeo/MaxMind/MaxMindUpdater.php` | MMDB 下载→校验→备份→原子替换→失败回滚 |
| `app/CustomGeo/Services/GeoIpLookupService.php` | IP→国家/地区/城市/时区，内网/非法 IP 返回空，带缓存 |
| `app/CustomGeo/Services/GeoDetectService.php` | 币种/语言推荐与 session 写入（不覆盖用户选择） |
| `app/CustomGeo/Http/Middleware/GeoDetectVisitor.php` | 前台首次访问自动定位（注册在 `bootstrap/app.php` 的 `front` 组，先于 `SetFrontLocale`） |
| `app/CustomGeo/Http/Controllers/GeoDebugController.php` | 定位调试接口 |
| `app/CustomGeo/Http/Controllers/GeoCityController.php` | 城市候选接口（地址表单 datalist） |
| `app/CustomGeo/Console/Commands/*.php` | 4 条 Artisan 命令（见 §4） |
| `database/seeders/GeoNamesSeeder.php` | Seeder 入口（全量/精简、续导、truncate） |
| `innopacks/common/database/migrations/2026_11_07_100000_add_customgeo_columns_to_countries_and_states.php` | 扩展原生两表（只加可空列） |
| `innopacks/common/database/migrations/2026_11_07_100100_create_customgeo_geo_tables.php` | `geo_admin2` / `geo_cities` |
| `innopacks/common/database/migrations/2026_11_07_100200_create_customgeo_import_tables.php` | `geo_import_runs` / `geo_import_errors` |

> 迁移放在 `innopacks/common/database/migrations`（InnoShop 原生迁移目录，由 `CommonServiceProvider::registerMigrations()` 加载）；Seeder 放 `database/seeders`。

### 2.2 原生源码改动清单（全部带 `zwxc-custom` 标记）

| 文件 | 改动 | 标记形式 |
|---|---|---|
| `bootstrap/providers.php` | 追加 `App\CustomGeo\CustomGeoServiceProvider::class` | `// zwxc-custom` |
| `innopacks/front/resources/views/shared/address-form.blade.php` | 城市输入加 `datalist`；默认国家优先用 IP 定位结果；州省切换刷新城市候选 | `{{-- zwxc-custom --}}` + JS 注释 |
| `innopacks/panel/resources/views/settings/_tools_setting.blade.php` | 新增“访客 IP 自动定位”开关 | `{{-- zwxc-custom --}}` |
| `lang/zh-cn/panel/setting.php`、`lang/en/panel/setting.php` | 开关文案 | 新增键（键名固定） |
| `database/seeders/SettingSeeder.php` | 默认值 `geo_auto_detect=1` | `// zwxc-custom` |
| `.gitignore` | 忽略 `storage/app/customgeo/`、`storage/app/geolite2/`、`*.mmdb`、`allCountries.*`、`countryInfo.txt` | 注释块 |
| `.env.example` | 新增 GeoNames / MaxMind / 缓存 / 调试变量 | 注释块 |

其余能力（路由、命令、中间件、定时任务）通过 Provider 注入，**未修改**任何 InnoShop 控制器、模型、仓库或中间件。

## 3. 数据模型

| 表 | 类型 | 说明 |
|---|---|---|
| `countries` | 原生 + 扩展列 | 新增 `geoname_id`、`iso_code_3`、`name_local`、`currency_code`、`phone_code`、`languages`、`timezone`、`latitude`、`longitude`、`population`、`geo_source`、`geo_updated_at`；原 `name/code/continent/position/active` 不变 |
| `states` | 原生 + 扩展列 | 新增 `geoname_id`、`name_local`、`admin1_code`、`level`、`timezone`、`latitude`、`longitude`、`geo_source`、`geo_updated_at`；语义仍为**一级行政区**（不强制翻译为“省”） |
| `geo_admin2` | 新增 | 二级行政区，不进入地址下拉，仅做归属/时区补充 |
| `geo_cities` | 新增 | 城市候选，`geoname_id` 唯一，关联 `country_id` / `state_id` |
| `geo_import_runs` | 新增 | 导入批次、断点行号、各类计数、状态、错误摘要 |
| `geo_import_errors` | 新增 | 逐行错误（上限由 `GEONAMES_MAX_ERRORS` 控制） |

**id 稳定性**：`countries` 按 `code`、`states` 按 `country_code+code` 原地更新，**不重建 id**，因此 `addresses.state_id`、`region_states.state_id`、订单历史地址不会断裂。这是“可回滚 + 不破坏下单”的关键。

## 4. 命令参考

```bash
# 1) 迁移（只加列/加表，可回滚）
php artisan migrate

# 2) 地区数据导入
php artisan innoshop:geo-import                        # 精简模式（config 白名单 + 人口>=500）
php artisan innoshop:geo-import --mode=full            # 全量（磁盘约 2GB，耗时较久）
php artisan innoshop:geo-import --countries=US,DE,FR --min-population=0
php artisan innoshop:geo-import --resume               # 断点续导
php artisan innoshop:geo-import --truncate --force     # 清空已导入数据后重建（保留被引用行）
php artisan innoshop:geo-import --dry-run              # 只统计不写库
php artisan innoshop:geo-import --no-download          # 复用本地已下载文件
php artisan innoshop:geo-import --from-file=/path/allCountries.txt --skip-admin2

# 3) Seeder 方式（等价入口，参数用环境变量）
php artisan db:seed --class=GeoNamesSeeder
GEONAMES_MODE=full php artisan db:seed --class=GeoNamesSeeder
GEONAMES_RESUME=true php artisan db:seed --class=GeoNamesSeeder

# 4) GeoLite2 IP 库
php artisan innoshop:geo-update-ipdb --edition=both            # 需要 MAXMIND_LICENSE_KEY
php artisan innoshop:geo-update-ipdb --edition=city --dry-run   # 只下载校验，不替换
php artisan innoshop:geo-update-ipdb --edition=country --format=zip
php artisan innoshop:geo-update-ipdb --license-key=xxxx --force

# 5) 区域（税率/运费共用）
php artisan innoshop:geo-zone-create "EU-Core" --countries=DE,FR,IT,NL
php artisan innoshop:geo-zone-create "US-West" --states=US:CA,US:WA,US:OR

# 6) 体检
php artisan innoshop:geo-status --ip=8.8.8.8
```

**进度与错误日志**：`storage/app/customgeo/logs/geo-import-YYYYMMDD.log`；失败明细落 `geo_import_errors` 表；批次状态看 `geo_import_runs`（`running / completed / failed`）。

**定时任务**：配置 `MAXMIND_LICENSE_KEY` 后，Provider 自动注册 `innoshop:geo-update-ipdb --edition=both` 每周三 04:10（`withoutOverlapping`，失败自动回滚）；服务器只需保留 `php artisan schedule:run` 的 cron。GeoNames 不做自动更新（数据量大约 1.5GB，且国家/行政区变动慢），建议每季度手动执行一次精简模式。

## 5. 清洗规则（`GeoNamesParser`）

| 规则 | 处理 |
|---|---|
| 海洋/水文/山脉/公园/景点 | 仅保留 feature class `A`（行政）与 `P`（居民点），`H/L/S/T/U/V/R` 全部丢弃 |
| 废弃/历史点位 | 丢弃 `PPLW`（已毁）、`PPLQ`（废弃）、`PPLH`（历史）、`PPLCH`（历史首都） |
| 城区碎片 | 丢弃 `PPLX` |
| 无效记录 | 无 2 位国家码、坐标越界或为 (0,0)、名称为空、`geonameid<=0` |
| 分类 | `ADM1`→`states`（level 1）；`ADM2`→`geo_admin2`；`PPL*`→`geo_cities`（level 3） |
| 英文名/本地名 | `name` = `asciiname`（英文/ASCII），`name_local` = `name`（本地语） |
| 时区/坐标 | 取原始 `timezone`、`latitude`、`longitude`；国家时区与中心坐标由下级数据聚合回填 |
| 一级行政区命名 | 语义保持“一级行政区”，**不统一改写成“省”** |

## 6. IP 定位与前台行为

- 查询顺序：`GeoLite2-City` → 无记录再查 `GeoLite2-Country`；两者都无 → 返回 `[]`。
- 非公网 IP（10/8、172.16/12、192.168/16、127/8、169.254/16、`::1` 等）与非法 IP：**静默返回空数组**，不抛异常。
- 缓存：key = `customgeo:{dbmtime}:ip:{ip}`，TTL 默认 86400；**MMDB 更新后自动失效**（版本段变化），无需手动清缓存。空结果不缓存。
- **数据现状（2026-11-08）**：仅落地国家库 `storage/app/geolite2/geolite2-country.mmdb`（3.39 MB）；官方城市库（58.8 MB）下载中断、未落地，**必须补**（见《独立站建设方案》§11.1 遗留项）。国家识别与语言自动切换不受影响，仅城市级定位与城市统计为空。
- 自动定位：中间件由 `bootstrap/app.php` 注册在 `front` 组内、位于 `SetFrontLocale` **之前**（2026-11-08 调整，原为组末尾），**每个会话仅执行一次**；后台“访客 IP 自动定位”关闭时不执行。
  - 币种：优先 `countries.currency_code`（GeoNames 提供），其次 `config('customgeo.detect.currency_by_country')`，最后校验该币种在后台已启用；
  - 语言：`config('customgeo.detect.locale_by_country')`，且必须在该站点已启用的语言内；未命中或对应语言未启用时回退前台默认语言（当前 `en`）；
  - 中文地区 `CN/TW/HK/MO` 统一映射 `zh-cn`、`SG` 映射 `en`（`zh-tw`/`zh-hk` 语言包未启用，避免回落英文）；
  - **不覆盖**用户已切换的 `session('currency')`；URL 已带语言段时不改语言；不跳转、不改 URL。
- 后台开关：设置 → 工具 → GeoLite2 → “访客 IP 自动定位”（`settings.name = geo_auto_detect`，默认 `1`）。

### 调试与城市接口

```bash
GET /customgeo/debug                    # 当前访客定位 + 建议币种/语言 + 数据量 + 库信息
GET /customgeo/debug?ip=8.8.8.8         # 指定 IP 调试
GET /customgeo/debug?token=xxx          # 生产环境（配合 CUSTOMGEO_DEBUG_TOKEN）
GET /customgeo/cities?country=US&state=CA&limit=200
```

生产环境建议 `CUSTOMGEO_DEBUG_ROUTE=false`，或至少设置 `CUSTOMGEO_DEBUG_TOKEN`。

## 7. 区域 / 税率 / 运费

- **税率**：`tax_rates.region_id → regions → region_states`（`state_id=0` 表示整国，`state_id>0` 表示州省）。用 `innoshop:geo-zone-create` 建好区域后，在后台「税率」里选择该区域即可，无需改核心代码。
- **运费**：InnoShop 原生运费按 `shipping_method_code` 取插件报价，**没有国家/区域维度**。要按区域计费，在插件 `Boot.php::getQuotes()` 内使用同一套 zone 判断（`region_states` where `country_id/state_id`），不要在核心 `Services/Fee/Shipping.php` 里改。
- 后台原有「国家 / 州省 / 区域」管理入口全部保留，可继续手工维护；GeoNames 导入只更新地理字段，不动 `active`、`position`。

## 8. 许可与署名（必须保留）

| 数据 | 许可 | 要求 |
|---|---|---|
| GeoNames（`allCountries`、`countryInfo`） | CC BY 4.0 | 站点或关于页署名 “GeoNames (https://www.geonames.org/)”；不得声称由本系统原创 |
| MaxMind GeoLite2 | CC BY-SA 4.0 | 署名 “GeoLite2 data created by MaxMind, available from https://www.maxmind.com”；需注册免费账号获取 license key |

- 免费注册：<https://www.maxmind.com/en/geolite2/signup>，key 写入 `.env` 的 `MAXMIND_LICENSE_KEY`（只放 `.env`，`.env.example` 留空）。
- 原始数据与 `*.mmdb` 已在 `.gitignore` 中忽略，**禁止提交 Git**。

## 9. 测试流程（保证下单/地址/运费不破坏）

> 建议顺序执行；任一步失败即停下回滚（见 §10），不要带病上线。

**T0 静态与迁移**
1. `php -l` 全部新增文件；`php artisan route:list | findstr customgeo` 应看到 2 条路由。
2. `php artisan migrate` → 检查 `countries/states` 新列、`geo_*` 表；`php artisan migrate:rollback --step=3` 应能干净回滚，再次 `migrate` 成功。

**T1 地区数据**
3. `php artisan innoshop:geo-import --mode=slim --dry-run` → 有行数/城市统计输出，无写库。
4. `php artisan innoshop:geo-import --mode=slim` → 完成；`innoshop:geo-status` 三项计数非 0。
5. **地址不断裂**：对比导入前后 `SELECT COUNT(*) FROM addresses WHERE state_id > 0 AND state_id NOT IN (SELECT id FROM states)` 应为 0。
6. 后台 → 国家/州省列表可正常分页、编辑、启停；导入后 `active` 未被强制改动。

**T2 地址与结算（前台）**
7. 前台注册 → 我的地址 → 新增地址：国家下拉按新数据展示；选美国出现州省；城市输入出现候选且可自由输入。
8. 提交后地址保存成功，编辑回显正常（国家/州省/城市均正确）。
9. 结算页填写收货地址 → 进入配送方式 → 提交订单：金额、运费、税费展示正常。

**T3 税费与运费**
10. 后台建一个区域（`innoshop:geo-zone-create` 或手工），绑定税率；用该区域国家/州省地址下单，税额与预期一致。
11. 用区域外地址下单：不命中该税率；运费按原插件逻辑正常计算。
12. 确认 `region_states` 原有数据未被导入动作改动（导入只写 `countries/states`）。

**T4 IP 定位**
13. `GET /customgeo/debug?ip=8.8.8.8` → 返回 US 及城市/时区；`?ip=192.168.1.10` → `location` 为空、`is_public=false`。
14. 清 session（换隐私窗口）访问首页 → 币种/语言按定位推荐；手动切换币种后刷新 → 不被覆盖。
15. 后台关闭“访客 IP 自动定位” → 新会话不再推荐，页面无异常。
16. `php artisan innoshop:geo-update-ipdb --edition=city --dry-run` → 校验通过且旧库未被替换；正式更新后 `storage/app/geolite2/backup/` 出现备份。

**T5 回归红线（必测）**
17. 多语言：`/en`、`/zh-cn`（及已启用语言）首页、商品页、结算页可访问，语言切换正常。
18. 多币种：币种切换、价格换算、下单币种与订单金额一致。
19. 访客统计：后台访问统计仍能记录国家/城市（复用原生 `GeoLite2Service` 路径）。
20. 后台设置保存：工具页保存后开关状态持久化，`settings` 表新增行正确。

## 10. 回滚

```bash
php artisan migrate:rollback --step=3     # 删 geo_* 表 + 移除 countries/states 扩展列
```

- 回滚只删本集成的列/表，原生数据（国家、州省、地址、区域、税率）保留。
- 若地址/地区需要完全恢复到导入前，请使用导入前的数据库备份（导入前务必 `mysqldump`）。
- MMDB 回滚：`storage/app/customgeo/maxmind/backup/{edition}-YYYYmmddHHMMSS.mmdb` 覆盖回 `config('customgeo.maxmind.city_path')` 即可；更新失败时程序会自动回滚。
- 关闭能力：`CUSTOMGEO_ENABLED=false` 或后台关闭开关 → 定位不执行；`CUSTOMGEO_DEBUG_ROUTE=false` → 调试路由 404。

## 11. 冲突与合并提醒

1. **所有原生改动都有标记**：`// zwxc-custom`、`{{-- zwxc-custom --}}`、`/* zwxc-custom */`；合并上游时先 `grep -rn "zwxc-custom"` 得到完整冲突面。
2. 上游若调整 `countries` / `states` 结构：本集成的列均为可空新增列，通常可自动合并；若上游新增同名列，以迁移冲突为准，先改名再合并。
3. 上游若替换地区数据命令（`country:update` / `state:update`）：不要删除它们，保持并存；**不要**再用旧命令覆盖 GeoNames 导入结果（旧命令会 truncate `countries/states`，会清空 `geoname_id` 与引用关系）。
4. 上游若已自带 GeoLite2 下载（`GeoLite2Service` 从 `res.innoshop.net` 下载）：本集成默认写入同一个 `GEOLITE2_PATH`，二者不冲突；如需只用官方源，请在文档中告知运维不再点击后台旧下载按钮。
5. 视图合并：`address-form.blade.php` 的改动集中在城市 `datalist` 与默认国家两处，可整体摘除而不影响其它逻辑。
6. `vendor/` 未提交；本集成**没有新增任何 Composer 依赖**（使用已装的 `geoip2/geoip2` 与原生 cURL/PharData）。
