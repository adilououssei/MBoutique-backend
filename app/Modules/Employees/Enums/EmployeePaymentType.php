<?php

namespace App\Modules\Employees\Enums;

enum EmployeePaymentType: string
{
    case Salary = 'salaire';

    /** Acompte versé avant la paie. */
    case Advance = 'avance';

    case Bonus = 'prime';
}
