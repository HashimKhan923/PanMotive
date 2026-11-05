<?php

namespace App\Services\Tax;

use RuntimeException;

/** The tax on an order couldn't be worked out, so the order must not go ahead with the wrong total. */
class SalesTaxUnavailable extends RuntimeException {}
