<?php

namespace App\Modules\Expenses\Policies;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Tenancy\Models\Store;

class ExpensePolicy
{
    public function viewAny(User $user, Store $store): bool
    {
        return $user->can('depenses.voir');
    }

    public function view(User $user, Expense $expense, Store $store): bool
    {
        return $expense->boutique_id === $store->id && $user->can('depenses.voir');
    }

    public function create(User $user, Store $store): bool
    {
        return $user->can('depenses.creer');
    }

    public function cancel(User $user, Expense $expense, Store $store): bool
    {
        return $expense->boutique_id === $store->id && $user->can('depenses.annuler');
    }
}
