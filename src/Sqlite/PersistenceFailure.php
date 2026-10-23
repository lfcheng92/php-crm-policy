<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Sqlite;

/** A database write did not preserve the required state; the entire operation is rolled back. */
final class PersistenceFailure extends \RuntimeException {}
