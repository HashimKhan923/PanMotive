<?php

namespace App\Jobs;

/** Thrown inside a bulk import when the seller presses Cancel; the job stops at the next product. */
class ImportCancelled extends \RuntimeException {}
