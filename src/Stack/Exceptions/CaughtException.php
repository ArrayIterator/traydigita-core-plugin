<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Exceptions;

use ErrorException;
use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Exceptions\ExceptionInterface;

class CaughtException extends ErrorException implements ExceptionInterface
{
}
