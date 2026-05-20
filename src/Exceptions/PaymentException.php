<?php

declare(strict_types=1);

namespace Syriable\Payments\Exceptions;

use RuntimeException;

/**
 * Root exception for everything thrown by this package.
 *
 * Consumers can catch this single class to handle any payment-related
 * failure, then inspect the concrete subclass if they need to branch.
 */
class PaymentException extends RuntimeException {}
