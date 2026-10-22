<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

enum Reason: string
{
    case TenantMismatch = 'tenant_mismatch';
    case SnapshotExpired = 'snapshot_expired';
    case ActorNotFound = 'actor_not_found';
    case ActorInactive = 'actor_inactive';
    case PermissionDenied = 'permission_denied';
    case DepartmentMissing = 'department_missing';
    case CustomerNotFound = 'customer_not_found';
    case AlreadyOwned = 'already_owned';
    case NotOwned = 'not_owned';
    case OwnerNotFound = 'owner_not_found';
    case TargetNotFound = 'target_not_found';
    case TargetInactive = 'target_inactive';
    case SameOwner = 'same_owner';
    case Protected = 'protected';
    case CapacityMissing = 'capacity_missing';
    case CapacityExceeded = 'capacity_exceeded';
    case InconsistentSnapshot = 'inconsistent_snapshot';
}
