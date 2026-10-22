<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Sqlite;

/** Opt-in example schema. Use a new, dedicated SQLite database, never an existing CRM schema. */
final class Schema
{
    public static function create(\PDO $pdo): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') { throw new \InvalidArgumentException('SQLite PDO required.'); }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(<<<'SQL'
CREATE TABLE crm_members (
    tenant_id TEXT NOT NULL, id TEXT NOT NULL, department_id TEXT,
    active INTEGER NOT NULL CHECK (active IN (0,1)),
    capacity_limit INTEGER CHECK (capacity_limit >= 0), roles_json TEXT NOT NULL,
    PRIMARY KEY (tenant_id, id)
);
CREATE TABLE crm_customers (
    tenant_id TEXT NOT NULL, id TEXT NOT NULL, owner_id TEXT, department_id TEXT,
    status TEXT NOT NULL CHECK (status IN ('active','won','abandoned')),
    protected INTEGER NOT NULL CHECK (protected IN (0,1)), protection_until TEXT,
    version INTEGER NOT NULL CHECK (version >= 0), PRIMARY KEY (tenant_id, id),
    FOREIGN KEY (tenant_id, owner_id) REFERENCES crm_members(tenant_id, id),
    CHECK (protected = 1 OR protection_until IS NULL)
);
CREATE INDEX crm_capacity ON crm_customers(tenant_id, owner_id, status);
CREATE TABLE crm_requests (
    tenant_id TEXT NOT NULL, request_id TEXT NOT NULL, fingerprint TEXT NOT NULL,
    result_json TEXT NOT NULL, PRIMARY KEY (tenant_id, request_id)
);
SQL);
    }
}
