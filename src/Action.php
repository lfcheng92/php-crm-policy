<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

enum Action: string
{
    case Claim = 'claim';
    case ReturnToPool = 'return';
    case Transfer = 'transfer';
}
