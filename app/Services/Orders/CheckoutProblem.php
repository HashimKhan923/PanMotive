<?php

namespace App\Services\Orders;

/** Why an order can't be placed as it is, tied to the form field the shopper should fix. */
class CheckoutProblem extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
