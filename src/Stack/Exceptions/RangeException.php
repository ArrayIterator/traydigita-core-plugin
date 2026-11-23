<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Stack\Exceptions;

use TrayDigita\WP\Plugin\Core\Stack\Interfaces\Exceptions\ExceptionInterface;

class RangeException extends \RangeException implements ExceptionInterface
{
}
