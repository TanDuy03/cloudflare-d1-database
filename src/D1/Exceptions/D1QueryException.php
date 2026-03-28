<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Exceptions;

/**
 * Thrown when a SQL query fails against the Cloudflare D1 API.
 *
 * Used in D1Pdo::exec() and D1PdoStatement::execute().
 */
class D1QueryException extends D1Exception
{
}
