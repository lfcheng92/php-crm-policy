# Contributing

Use PHP 8.2 or newer. Development checks need PDO SQLite, DOM/XML, mbstring,
ZipArchive and proc_open. Install development dependencies from the locked file:

```sh
composer install --no-interaction --prefer-dist --no-plugins
composer validate --strict
composer check
php tools/consumer-test.php
composer audit --locked
```

The core must remain independent of frameworks, global databases and messaging.
Changes to decisions need explicit tenant, permission, protection, capacity and
batch semantics. Changes to persistence need error/rollback/idempotency tests.
Keep all fixtures synthetic and all integration databases isolated. Never run
these tests against production or reuse customer data.

The SQLite adapter is a reference protocol for a dedicated database. All writers
must cooperate. Do not broaden production or cross-database guarantees based
on one test environment. CI configuration is not evidence of a completed run.

Keep credentials, database files, logs, vendor and machine-specific audit notes
out of Git. Report security issues privately through an established trusted
channel; do not put sensitive records in public issues. See SECURITY.md.

Contributions must be original or include compatible rights and attribution.
The repository's original code is MIT licensed; dependency licenses are separate.
