<?php

namespace App\Exceptions;

use Exception;

class NoAvailabilityException extends Exception
{
    protected $message = 'No availability remaining for this service.';
}
