<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy;

enum Scope: int
{
    case Own = 1;
    case Department = 2;
    case Tenant = 3;
}
