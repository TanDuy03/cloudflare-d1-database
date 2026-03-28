<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\D1\Exceptions;

/**
 * Thrown when an unsupported PDO feature is used.
 *
 * For example, an unsupported fetch mode in D1PdoStatement::formatRow().
 */
class D1UnsupportedFeatureException extends D1Exception
{
}
