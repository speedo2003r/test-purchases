<?php

namespace App\Exceptions;

use Exception;

class ServiceNotAvailableException extends Exception
{
    protected $message = 'This service is not currently available for purchase.';
}
