<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy;

/** Pure decisions: no queries, writes, authentication, notifications or global state. */
final readonly class PolicyEngine
{
    public function __construct(private CapacityPolicy $capacity, private Clock $clock = new SystemClock(), private int $maxSnapshotAgeSeconds = 60)
    {
        if ($maxSnapshotAgeSeconds < 0) { throw new \InvalidArgumentException('Snapshot age must be non-negative.'); }
    }

    public function decide(Command $command, Snapshot $snapshot): Decision
    {
        $now = $this->clock->now();
        if ($snapshot->tenantId !== $command->tenantId) { return Decision::deny(Reason::TenantMismatch); }
        $wholeSeconds = $now->getTimestamp() - $snapshot->capturedAt->getTimestamp();
        $expired = $wholeSeconds > $this->maxSnapshotAgeSeconds
            || ($wholeSeconds === $this->maxSnapshotAgeSeconds && $now->format('u') > $snapshot->capturedAt->format('u'));
        if ($snapshot->capturedAt > $now || $expired) {
            return Decision::deny(Reason::SnapshotExpired);
        }
        $actor = $snapshot->member($command->actorId);
        if ($actor === null) { return Decision::deny(Reason::ActorNotFound); }
        if ($actor->tenantId !== $command->tenantId) { return Decision::deny(Reason::TenantMismatch); }
        if (!$actor->active) { return Decision::deny(Reason::ActorInactive); }
        $scope = $actor->scopeFor($command->action);
        if ($scope === null) { return Decision::deny(Reason::PermissionDenied); }
        if ($actor->departmentId === null) { return Decision::deny(Reason::DepartmentMissing); }
        $targetId = match ($command->action) {
            Action::Claim => $actor->id,
            Action::Transfer => $command->targetId,
            Action::ReturnToPool => null,
        };
        $target = $targetId === null ? null : $snapshot->member($targetId);
        if ($targetId !== null) {
            if ($target === null) { return Decision::deny(Reason::TargetNotFound); }
            if ($target->tenantId !== $command->tenantId) { return Decision::deny(Reason::TenantMismatch); }
            if (!$target->active) { return Decision::deny(Reason::TargetInactive); }
            if ($target->departmentId === null) { return Decision::deny(Reason::DepartmentMissing); }
            if ($scope !== Scope::Tenant && $target->departmentId !== $actor->departmentId) { return Decision::deny(Reason::PermissionDenied); }
            if ($target->capacityLimit === null || $snapshot->usageFor($target->id) === null) { return Decision::deny(Reason::CapacityMissing); }
        }
        $changes = [];
        $deltas = [];
        foreach ($command->customerIds as $id) {
            $customer = $snapshot->customer($id);
            if ($customer === null) { return Decision::deny(Reason::CustomerNotFound, $id); }
            if ($customer->tenantId !== $command->tenantId) { return Decision::deny(Reason::TenantMismatch, $id); }
            if ($customer->departmentId === null) { return Decision::deny(Reason::DepartmentMissing, $id); }
            if ($command->action === Action::Claim && $customer->ownerId !== null) { return Decision::deny(Reason::AlreadyOwned, $id); }
            if ($command->action !== Action::Claim && $customer->ownerId === null) { return Decision::deny(Reason::NotOwned, $id); }
            $canAccess = match ($scope) {
                Scope::Tenant => true,
                Scope::Department => $customer->departmentId === $actor->departmentId,
                Scope::Own => $command->action === Action::Claim
                    ? $customer->departmentId === $actor->departmentId
                    : $customer->ownerId === $actor->id,
            };
            if (!$canAccess) { return Decision::deny(Reason::PermissionDenied, $id); }
            if ($customer->isProtectedAt($now)) { return Decision::deny(Reason::Protected, $id); }
            if ($target !== null && $customer->ownerId === $target->id) { return Decision::deny(Reason::SameOwner, $id); }
            if ($customer->ownerId !== null) {
                $owner = $snapshot->member($customer->ownerId);
                if ($owner === null) { return Decision::deny(Reason::OwnerNotFound, $id); }
                if ($owner->tenantId !== $command->tenantId) { return Decision::deny(Reason::TenantMismatch, $id); }
                if ($owner->departmentId === null) { return Decision::deny(Reason::DepartmentMissing, $id); }
                if ($owner->departmentId !== $customer->departmentId) { return Decision::deny(Reason::InconsistentSnapshot, $id); }
                if ($this->capacity->counts($customer->status)) {
                    if ($snapshot->usageFor($owner->id) === null) { return Decision::deny(Reason::CapacityMissing, $id); }
                    $deltas[$owner->id] = ($deltas[$owner->id] ?? 0) - 1;
                }
            }
            if ($target !== null && $this->capacity->counts($customer->status)) { $deltas[$target->id] = ($deltas[$target->id] ?? 0) + 1; }
            $changes[] = new CustomerChange($id, $customer->ownerId, $target?->id, $customer->departmentId, ($target === null ? $customer->departmentId : $target->departmentId), $customer->version);
        }
        foreach ($deltas as $id => $delta) {
            $usage = $snapshot->usageFor((string) $id);
            if ($usage === null) { return Decision::deny(Reason::CapacityMissing); }
            if ($delta < 0 && $usage < -$delta) { return Decision::deny(Reason::InconsistentSnapshot); }
        }
        if ($target !== null) {
            $usage = $snapshot->usageFor($target->id);
            $limit = $target->capacityLimit;
            // Compare by subtraction to avoid integer overflow near PHP_INT_MAX.
            if (($deltas[$target->id] ?? 0) > $limit - $usage) { return Decision::deny(Reason::CapacityExceeded); }
        }
        return Decision::allow(new ChangePlan($command->tenantId, $command->actorId, $command->requestId, $command->action, $changes, $deltas, $now));
    }
}
