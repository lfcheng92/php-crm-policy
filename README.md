# CRM Policy

[English](README.en.md)

框架无关的 PHP 8.2+ CRM 客户公海、归属和容量策略。包名 `lfcheng92/crm-policy`，PSR-4 命名空间 `Lfcheng92\CrmPolicy`。领域层无第三方运行时依赖，不查询数据库，不发送通知。

这是独立首版，供本地与后续私有验证。尚未发布 Packagist，也未授予开源许可。来源与权利边界见 [SOURCES.md](SOURCES.md)、[LICENSE](LICENSE)、[NOTICE.md](NOTICE.md)。重新实现不自动解决权属问题。

## 安装

当前使用本地 path 仓库，在消费者的 `composer.json` 中添加：

```json
{
  "repositories": [{
    "type": "path",
    "url": "../php-crm-policy",
    "options": {"symlink": false, "versions": {"lfcheng92/crm-policy": "0.1.0"}}
  }],
  "require": {"lfcheng92/crm-policy": "0.1.0"}
}
```

在消费者目录执行 `composer install`，通过消费者自己的 `vendor/autoload.php` 加载。不要将开发者机器的绝对路径写入应用。可选 SQLite 适配器需要 `ext-pdo`、`ext-pdo_sqlite`；开发测试还需 PHPUnit 所需的 XML/DOM/mbstring 扩展；源码归档消费者测试需 ZipArchive 和 `proc_open`。

## 最小 API

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Lfcheng92\CrmPolicy\{
    Action, CapacityPolicy, Command, Customer, CustomerStatus,
    Member, PolicyEngine, Role, Scope, Snapshot, SystemClock
};

$clock = new SystemClock();
$actor = new Member('tenant-a', 'seller-1', 'sales', true, 20, [
    new Role('seller', ['claim' => Scope::Own, 'return' => Scope::Own]),
]);
$customer = new Customer(
    'tenant-a', 'sample-1', null, 'sales', CustomerStatus::Active, false, null, 0,
);
// 必须由可信数据源构造完整快照；计数口径必须和 CapacityPolicy 一致。
$snapshot = new Snapshot('tenant-a', [$customer], [$actor], ['seller-1' => 19], $clock->now());
$command = new Command('tenant-a', 'seller-1', 'request-001', Action::Claim, ['sample-1']);
$decision = (new PolicyEngine(new CapacityPolicy(countWon: false, countAbandoned: false), $clock))
    ->decide($command, $snapshot);

if (!$decision->allowed) {
    echo $decision->reason->value; // 例如 capacity_exceeded；拒绝时 plan 为 null
} else {
    $plan = $decision->plan; // 仅为提案，不会写数据库或发送通知
    echo $plan->changes[0]->nextOwnerId;
}
```

`Member` 的角色名没有默认权限；只有 `Role::$grants` 中显式列出的 action/scope 才生效。多个角色对同一操作取最宽的显式范围。调用方必须从已认证会话取得 tenant/actor，不能直接信任 HTTP 请求中的角色或身份。

| 类型 / 入口 | 职责 |
| --- | --- |
| `Command(tenantId, actorId, requestId, action, customerIds, targetId?)` | 操作输入；transfer 必须有目标，claim 固定认领给操作者，return 禁止目标 |
| `Customer` / `Member` / `Role` | 不可变输入值对象；不依赖 ORM 或框架 |
| `Snapshot(tenantId, customers, members, capacityUsage, capturedAt)` | 同一租户、一致时点的数据及当前计数，不代表锁 |
| `CapacityPolicy(countWon, countAbandoned)` | active 总计入；won/abandoned 是否计入独立配置 |
| `PolicyEngine::decide(Command, Snapshot): Decision` | 纯判断，全批成功或全批拒绝 |
| `Decision` | `allowed`、`reason`、可选 `customerId`、可选 `plan` |
| `ChangePlan` / `CustomerChange` | 原/新归属、原/新部门、期望版本、容量差额、决策时间 |
| `Clock::now(): DateTimeImmutable` | 可注入时钟；默认 `SystemClock` 为 UTC |
| `Sqlite\Schema::create(PDO)` | 在专用空 SQLite 数据库创建示例表；不迁移现有业务表 |
| `Sqlite\SqliteExecutor::execute(Command): ExecutionResult` | 事务内读取、判断、更新、持久化幂等结果；返回 decision/replayed |

## 归属、范围与保护

- `Claim` 仅接受 owner 为 null 的公海客户；`ReturnToPool` / `Transfer` 要求已有归属；转给当前 owner 返回 `same_owner`。
- `Scope::Own`：认领本部门公海；退回/转移自己的客户；转移目标限本部门。
- `Scope::Department`：客户当前部门及接收者部门都必须等于操作者部门。
- `Scope::Tenant`：可在同一租户跨部门操作。所有范围都禁止跨租户。
- 退回后保留原部门作为公海部门；认领或转移后部门变为接收者部门。已归属客户部门与其 owner 部门不一致时拒绝，不能靠宽权限绕过。
- 所有操作都遵守保护状态。`protected=true, protectionUntil=null` 表示无限期保护；时间等于截止点时保护失效。用显式 `DateTimeImmutable`，不会从全局时间或业务模型隐式读取。
- actor 必须存在且 active；接收者必须存在、active、部门和容量上限完整。原 owner 必须存在、同租户且部门一致；原 owner 停用后仍允许有权限的管理者回收/转移。
- 缺少角色授权、部门、客户、相关 owner、接收者容量或计数均不默认放行。`capacityLimit=null` 表示未知，**不表示无限容量**；需要大容量时显式配置整数。
- 所有客户通过相同规则；没有单条/批量绕过接口。输入先按精确 ID 去重并按字节排序，最多 200 个唯一客户、1000 个原始条目。数字字符串 `1` 和 `01` 保持不同。

## 容量与快照

接收容量按整个批次累计。等于上限允许，超过上限拒绝；已超额成员可以释放，但不接收额外客户。won/abandoned 的计数配置不改变其操作权限或保护规则。计数为零的状态在恰好满额时可接收；已超额时仍拒绝接收。owner 的待释放数量大于快照已用数时拒绝为 `inconsistent_snapshot`。

快照必须由可信存储提供，不能遗漏现有客户、伪造计数或混用不同 `CapacityPolicy`。引擎可以检查缺失和局部矛盾，不能证明一个调用方提供的计数真实完整。默认快照最大年龄 60 秒，可通过第三个构造参数调整；未来快照或超时快照拒绝。年龄检查不是并发控制：两个调用者可以基于相同旧计数同时获得允许结果。

**不要直接应用离线 ChangePlan。** 自定义执行器应在锁/隔离事务内重新读取授权、归属、保护状态、容量，再执行全部变更及版本比较；事务失败必须全部回滚。状态变更、人工分配及其他写入方也必须采用相同容量协议。提交后通知与可靠投递由应用负责，本包没有通知或队列。

## SQLite/PDO 参考执行器

```php
use Lfcheng92\CrmPolicy\Sqlite\{Schema, SqliteExecutor};

$pdo = new PDO('sqlite::memory:'); // 示例：真实集成测试使用隔离临时文件
Schema::create($pdo);             // 仅对新建专用数据库执行一次
// 使用 prepared statements 写入应用验证后的成员、角色及客户，见 examples/sqlite.php。
$executor = new SqliteExecutor($pdo, new CapacityPolicy(false, false), $clock);
// $result = $executor->execute($command); // 使用数据库中的身份权限，不接受外部 ChangePlan
```

执行器使用 SQLite `BEGIN IMMEDIATE`，先获得写事务，再读取限定 tenant 的客户、成员与实际容量计数；单批次事务应用预期版本和原 owner 条件更新。客户变更与幂等结果在同一事务提交，SQL 异常回滚。示例表通过 `(tenant_id,id)`、复合归属外键和租户限定查询隔离同 ID 客户。SQLite 事务依据见 [官方说明](https://www.sqlite.org/lang_transaction.html)。

`(tenant_id,request_id)` 是幂等键。相同规范化命令返回最初结果，成功和业务拒绝都会缓存；顺序不同或重复 ID 不改变指纹。不同 actor/action/customer/target 复用同一键抛 `IdempotencyConflict`，不会覆盖结果。业务规则或容量后来改变时，新的尝试须使用新 requestId；原来的重试继续返回原结果。幂等结果不是“当前是否允许”的查询接口。

本适配器在构造、每次执行前及取得事务后只读检查 journal_mode，仅接受 DELETE/TRUNCATE/PERSIST/MEMORY/WAL；OFF 或未知模式抛 `LogicException`，不会自动切换日志模式。连接在构造后被改为 OFF 同样会被拒绝；调用方须自行恢复支持的模式后再重试。MEMORY 只提供进程存续时的事务回滚，不增加断电/宕机保证。

本适配器要求专用 PDO 连接，无外层事务；显式 SQL COMMIT/ROLLBACK 与 BEGIN 配对。`busy_timeout=5000`，锁等待超过时间、数据库不可写或非法持久数据会抛异常，不伪装成业务拒绝。存储时间必须是绝对格式 `Y-m-d\TH:i:s.uP`（微秒和时区偏移），例如 `2026-01-01T12:00:00.000000+00:00`；不接受相对日期。`Schema::create` 不使用 `IF NOT EXISTS` 来掩盖已有不兼容 schema。

这是可运行的 SQLite 协议示例，未宣称任意应用数据库自动并发安全。未验证网络文件系统、跨主复制、MySQL/PostgreSQL 或绕过协议的其他写入者。需要生产审计表、版本化迁移、幂等记录保留策略和业务状态更新接口时由应用补足；删除幂等记录会缩短去重保证期。

## 拒绝与异常

拒绝返回 `Decision`，不抛业务异常。只返回按规范化批次顺序遇到的第一个原因，拒绝时不返回部分计划：

`tenant_mismatch`、`snapshot_expired`、`actor_not_found`、`actor_inactive`、`permission_denied`、`department_missing`、`customer_not_found`、`already_owned`、`not_owned`、`owner_not_found`、`target_not_found`、`target_inactive`、`same_owner`、`protected`、`capacity_missing`、`capacity_exceeded`、`inconsistent_snapshot`。

无效输入（空 ID、非法 UTF-8、控制字符、空/超大批次、负容量/版本、非法 grant 等）抛 `InvalidArgumentException`；错误 PHP 参数类型可能抛 `TypeError`。ID 为 1..128 UTF-8 字节，禁止首尾空白和 ASCII 控制字符，大小写敏感，不做名称匹配。

执行异常还包括 `IdempotencyConflict`、防御性版本更新失败 `ConcurrentChange`、外层事务 `LogicException`、PDO/JSON/非法存储数据异常。BEGIN 失败没有执行任何业务写入；其他失败尝试回滚，回滚失败则抛 `RuntimeException`，必须丢弃连接并调查。应用自行制定有界重试策略，重试同一命令保持相同 requestId；不得把异常转换为允许。

## 示例与验证

以下开发命令适用于完整源仓库。Composer 消费归档只包含运行源码、示例及必要说明，按配置排除 tests/tools/CI/开发锁文件；消费者使用自己的自动加载器，不在已安装依赖中运行开发检查。

```sh
composer install --no-interaction --prefer-dist
composer validate --strict
composer check
php tools/consumer-test.php
composer audit --locked
```

- `examples/agency.php`：成交和放弃不占潜客容量。
- `examples/education.php`：成交继续占顾问容量，放弃不占。
- `examples/sqlite.php`：内存 SQLite 实际执行及幂等重放。
- `tools/consumer-test.php [composer.phar]`：先归档源码并检查清单，再在干净目录安装镜像 path 包；禁用 Packagist/网络并删除临时源目录后运行消费者和三个示例。
- PHPUnit 覆盖错误路径、租户/权限/保护/容量边界、触发器故障回滚及两个独立 PHP 进程争抢同客户/同请求/最后一个容量位；没有使用生产服务。
- PHPStan level 8 分析 src 与 examples；保留 PHPDoc 所描述输入的运行时校验（`treatPhpDocTypesAsCertain: false`）。

CI 已配置 PHP 8.2/8.3/8.4/8.5；配置不等于已执行。实际本地运行记录在开发目录的 `LOCAL_VERIFICATION.md`，该记录不随源码归档分发。发布边界见 [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md)。
