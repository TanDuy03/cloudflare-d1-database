<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Exceptions;

/**
 * Thrown when transaction operations are attempted.
 *
 * D1 does not support transactions over stateless HTTP.
 */
class D1TransactionException extends D1Exception {}
