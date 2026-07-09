<?php

namespace App\Services\Booking\Exceptions;

use RuntimeException;

/**
 * A booking domain rule was violated (illegal state transition, missing passport
 * when the fare requires it, etc.). Controllers surface the message to the user.
 */
class BookingException extends RuntimeException {}
