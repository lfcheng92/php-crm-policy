<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Sqlite;

use Lfcheng92\CrmPolicy\{Action, ChangePlan, CustomerChange, Decision, Reason};

/** @internal Persisted format v1; do not edit idempotency records manually. */
final class DecisionCodec
{
    public static function encode(Decision $decision): string
    {
        $plan = $decision->plan;
        return json_encode([
            'v' => 1, 'allowed' => $decision->allowed, 'reason' => $decision->reason?->value, 'customer' => $decision->customerId,
            'plan' => $plan === null ? null : [
                'tenant' => $plan->tenantId, 'actor' => $plan->actorId, 'request' => $plan->requestId,
                'action' => $plan->action->value, 'at' => $plan->decidedAt->format('Y-m-d\TH:i:s.uP'),
                'deltas' => array_map(static fn ($id, int $delta): array => ['id' => (string) $id, 'delta' => $delta], array_keys($plan->capacityDeltas), array_values($plan->capacityDeltas)),
                'changes' => array_map(static fn (CustomerChange $c): array => [
                    'id' => $c->customerId, 'from' => $c->previousOwnerId, 'to' => $c->nextOwnerId,
                    'fromDept' => $c->previousDepartmentId, 'toDept' => $c->nextDepartmentId, 'version' => $c->expectedVersion,
                ], $plan->changes),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public static function decode(string $json): Decision
    {
        $data = Fields::object(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        if (($data['v'] ?? null) !== 1) { throw new \UnexpectedValueException('Unknown decision format.'); }
        if (!Fields::bool($data['allowed'] ?? null)) {
            if (($data['plan'] ?? null) !== null) { throw new \UnexpectedValueException('Rejected result cannot have a plan.'); }
            return Decision::deny(Reason::from(Fields::string($data['reason'] ?? null)), Fields::nullableString($data['customer'] ?? null));
        }
        if (($data['reason'] ?? null) !== null || ($data['customer'] ?? null) !== null) { throw new \UnexpectedValueException('Allowed result cannot have rejection metadata.'); }
        $plan = Fields::object($data['plan'] ?? null);
        $changes = [];
        foreach (Fields::list($plan['changes'] ?? null) as $item) {
            $c = Fields::object($item);
            $changes[] = new CustomerChange(Fields::string($c['id'] ?? null), Fields::nullableString($c['from'] ?? null), Fields::nullableString($c['to'] ?? null), Fields::string($c['fromDept'] ?? null), Fields::string($c['toDept'] ?? null), Fields::int($c['version'] ?? null));
        }
        if ($changes === []) { throw new \UnexpectedValueException('Allowed result requires changes.'); }
        $deltas = [];
        foreach (Fields::list($plan['deltas'] ?? null) as $item) {
            $d = Fields::object($item);
            $deltas[Fields::string($d['id'] ?? null)] = Fields::int($d['delta'] ?? null);
        }
        return Decision::allow(new ChangePlan(Fields::string($plan['tenant'] ?? null), Fields::string($plan['actor'] ?? null), Fields::string($plan['request'] ?? null), Action::from(Fields::string($plan['action'] ?? null)), $changes, $deltas, Fields::instant($plan['at'] ?? null)));
    }
}
