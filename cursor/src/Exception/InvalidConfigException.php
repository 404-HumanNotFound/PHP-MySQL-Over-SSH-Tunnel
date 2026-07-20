<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Exception;

use InvalidArgumentException;

/**
 * Thrown when tunnel configuration fails validation (programmer error).
 *
 * Environmental failures (missing SSH binary, tunnel timeout, disallowed
 * environment) must never throw this — they degrade to a logged warning and
 * direct-connection fallback instead.
 */
final class InvalidConfigException extends InvalidArgumentException
{
}
