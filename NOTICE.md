# Dependency notices

The domain core has no third-party runtime package dependency. PHP, PDO and SQLite are platform components and retain their own terms. The optional SQLite adapter is newly written example code; SQLite itself is not bundled.

Development dependencies include PHPUnit (BSD-3-Clause) and PHPStan (MIT), plus their transitive packages. The exact installed versions, source references and license metadata are recorded in composer.lock. Their original notices remain in vendor during local development. vendor is ignored by Git and excluded from source archives; dependencies are not relicensed as this package's code.

CI configuration refers to upstream actions/checkout and shivammathur/setup-php by reference; it does not copy their implementations. Review and pin their immutable revisions before a public release if the distribution policy requires it.

本包目前没有选择开源许可证。proprietary 仅表示当前分发许可未开放，不据此对公司或第三方代码主张权利。
