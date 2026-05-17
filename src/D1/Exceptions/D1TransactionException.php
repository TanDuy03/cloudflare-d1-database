<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Exceptions;

/**
 * Thrown when DB::transaction() is called on a D1 connection
 * with transaction_mode set to 'exception'.
 *
 * D1 is stateless over HTTP — real transactions are impossible.
 * Use batch() for atomic multi-statement execution instead.
 */
class D1TransactionException extends D1Exception {}
