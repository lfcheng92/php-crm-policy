# 来源与许可边界 / Provenance and license scope

本库根据通用 CRM 需求独立实现：客户公海认领/退回/转移、显式角色与部门范围、保护状态、容量、批量一致性，以及纯决策与可测试的 SQLite 参考执行器。源码、测试及示例在新目录中创建；没有导入旧公司模型、源码、凭据、真实客户/组织数据或旧 Git 历史。样本租户、成员、客户及故障场景均为虚构数据。

核对本次实现记录及仓库内容后，原创部分采用 MIT 许可，见 LICENSE。此许可仅覆盖本仓库可自主授权的原创内容，不将公司、平台或第三方代码改为 MIT。独立实现方法本身不构成对任何雇佣、合同或保密义务的法律判定。

设计所依据的公开规范与官方文档（接口/协议说明，没有复制这些项目的实现）：

- [PSR-4](https://www.php-fig.org/psr/psr-4/)
- [Composer schema](https://getcomposer.org/doc/04-schema.md) 与 [path repositories](https://getcomposer.org/doc/05-repositories.md#path)
- [SQLite transactions](https://www.sqlite.org/lang_transaction.html)
- [PHP PDO](https://www.php.net/manual/en/book.pdo.php)
- [PHPUnit requirements](https://docs.phpunit.de/en/11.5/installation.html)

开发依赖从默认 Packagist 元数据及上游分发安装，版本与原许可记录于 composer.lock；vendor 和本机验收数据不进入源码归档。第三方声明见 NOTICE.md。

Original code was independently implemented from general requirements and is
licensed under MIT. No legacy employer implementation, credentials, private
business data or Git history were imported. Platform and dependency licenses
remain separate. This provenance record describes source boundaries; it does
not adjudicate contractual rights.
