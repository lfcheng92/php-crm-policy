<?php
declare(strict_types=1);
namespace Lfcheng92\CrmPolicy;

enum CustomerStatus: string
{
    case Active = 'active';
    case Won = 'won';
    case Abandoned = 'abandoned';
}
