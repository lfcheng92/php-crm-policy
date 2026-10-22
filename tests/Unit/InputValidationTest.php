<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy\Tests\Unit;

use Lfcheng92\CrmPolicy\{Action, CapacityPolicy, Command, PolicyEngine, Role, Scope, Snapshot};
use Lfcheng92\CrmPolicy\Tests\Support\{Fixtures as F, FrozenClock};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InputValidationTest extends TestCase
{
    #[DataProvider('invalidCases')]
    public function testInvalidInputsThrow(string $case): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $now = new \DateTimeImmutable('2026-01-01Z');
        match ($case) {
            'invalid utf8' => new Command('t', 'a', chr(255), Action::Claim, ['c']),
            'empty tenant' => new Command('', 'a', 'r', Action::Claim, ['c']),
            'blank actor' => new Command('t', ' a ', 'r', Action::Claim, ['c']),
            'control id' => new Command('t', 'a', "r\n", Action::Claim, ['c']),
            'long id' => new Command('t', 'a', str_repeat('x', 129), Action::Claim, ['c']),
            'empty batch' => new Command('t', 'a', 'r', Action::Claim, []),
            'non list' => new Command('t', 'a', 'r', Action::Claim, [2 => 'c']),
            'integer id' => new Command('t', 'a', 'r', Action::Claim, [1]),
            'empty customer' => new Command('t', 'a', 'r', Action::Claim, ['']),
            'large unique batch' => new Command('t', 'a', 'r', Action::Claim, array_map(strval(...), range(1, 201))),
            'large input batch' => new Command('t', 'a', 'r', Action::Claim, array_fill(0, 1001, 'c')),
            'missing transfer target' => new Command('t', 'a', 'r', Action::Transfer, ['c']),
            'claim target supplied' => new Command('t', 'a', 'r', Action::Claim, ['c'], 'b'),
            'return target supplied' => new Command('t', 'a', 'r', Action::ReturnToPool, ['c'], 'b'),
            'negative capacity' => F::member(limit: -1),
            'negative version' => F::customer(version: -1),
            'overflow version' => F::customer(version: PHP_INT_MAX),
            'unprotected deadline' => F::customer(until: $now),
            'unknown action grant' => new Role('role', ['delete' => Scope::Tenant]),
            'negative usage' => new Snapshot('t', [], [], ['a' => -1], $now),
            'non integer usage' => new Snapshot('t', [], [], ['a' => '1'], $now),
            'duplicate customer' => new Snapshot('t', [F::customer(), F::customer()], [], [], $now),
            'duplicate member' => new Snapshot('t', [], [F::member(), F::member()], [], $now),
            'negative age' => new PolicyEngine(new CapacityPolicy(false, false), new FrozenClock(), -1),
        };
    }

    public static function invalidCases(): iterable
    {
        foreach (['invalid utf8','empty tenant','blank actor','control id','long id','empty batch','non list','integer id','empty customer','large unique batch','large input batch','missing transfer target','claim target supplied','return target supplied','negative capacity','negative version','overflow version','unprotected deadline','unknown action grant','negative usage','non integer usage','duplicate customer','duplicate member','negative age'] as $case) { yield $case => [$case]; }
    }

    public function testMaximumBatchAcceptedAndCannotBeMutated(): void
    {
        $command = new Command('t', 'a', 'r', Action::Claim, array_map(strval(...), range(1, 200)));
        self::assertCount(200, $command->customerIds);
        $this->expectException(\Error::class);
        $command->customerIds[] = '201';
    }
}
