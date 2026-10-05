<?php

namespace App\Modules\Customers\Exceptions;

use RuntimeException;

/** Remboursement nul ou supérieur à la dette — docs/customers.md §12. */
class InvalidCustomerPaymentException extends RuntimeException {}
