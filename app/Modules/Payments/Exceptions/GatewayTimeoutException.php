<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/** The gateway call ended without a known outcome. */
final class GatewayTimeoutException extends RuntimeException {}
