<?php

namespace App\Modules\Employees\Enums;

enum SalaryPeriod: string
{
    case Monthly = 'mensuel';
    case Weekly = 'hebdomadaire';
    case Daily = 'journalier';
}
