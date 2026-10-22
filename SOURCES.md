# 来源与权利边界 / Source boundaries

本包在本次任务中根据通用需求独立编写：客户公海认领/退回/转移、显式角色与部门范围、保护状态、容量、批量一致性、纯决策及 SQLite 示例执行。没有读取、复制、移植或改名旧公司 CRM 模型、源码、凭据、真实客户/组织数据或 Git 历史。仅核查指定新目录、祖先约束文件与兄弟目录是否存在，未读取兄弟项目源码。所有 ID、角色、部门与客户样本均为虚构数据。

独立编写仅描述本次实现方法，不是权属结论，也不是对保密/合同/雇佣义务的法律判断。公开前须另行核实来源、权利及许可，不能以测试通过替代。

参考的公开主资料（仅接口/工具/协议说明，不复制第三方项目实现）：

- PSR-4 规范：https://www.php-fig.org/psr/psr-4/
- Composer 官方安装、校验与包配置：https://getcomposer.org/download/ 、https://getcomposer.org/doc/04-schema.md
- Composer path 仓库：https://getcomposer.org/doc/05-repositories.md#path
- SQLite 事务与写锁：https://www.sqlite.org/lang_transaction.html
- PHP PDO：https://www.php.net/manual/en/book.pdo.php
- PHPUnit 11.5 安装要求：https://docs.phpunit.de/en/11.5/installation.html

开发工具从默认官方 Packagist 元数据及上游分发安装，记录于 composer.lock，vendor 不属于源码交付；Composer PHAR 仅装在临时工具目录，安装器 SHA-384 经官方签名摘要核对。测试/源码中不包含任何 OAuth token、密钥或外部服务连接。

No legacy employer implementation, private data, credentials or history were used. Public specifications and official tool documentation informed interfaces and transaction handling. This records provenance boundaries, not a finding about ownership. Review rights separately before public distribution.
