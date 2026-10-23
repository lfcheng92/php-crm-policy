# License and dependency notices

Original code in this repository is licensed under the MIT License; see LICENSE.
The code was independently implemented from the general requirements recorded in
SOURCES.md. No employer or third-party implementation was copied or relicensed.

The domain core has no third-party runtime Composer dependency. PHP, PDO and
SQLite are platform components and retain their own terms; their implementations
are not bundled here.

Development dependencies include PHPUnit (BSD-3-Clause) and PHPStan (MIT), with
transitive packages under MIT or BSD-3-Clause. Exact versions, source references
and license metadata are recorded in composer.lock. Their notices remain with
those packages. vendor is ignored by Git and excluded from Composer archives;
the repository's MIT license does not replace dependency licenses.

CI refers to actions/checkout and shivammathur/setup-php by immutable upstream
commit references. Their implementations and terms remain with their authors.
