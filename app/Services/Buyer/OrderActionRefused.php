<?php

namespace App\Services\Buyer;

/** A buyer action that isn't allowed right now — with the form field it belongs to and the HTTP status the website used. */
class OrderActionRefused extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
