<?php

namespace App\Exceptions;

use Exception;

class PurchaseNotPayableException extends Exception
{
    protected $message = 'This purchase is not in a state that accepts payment.';
}
