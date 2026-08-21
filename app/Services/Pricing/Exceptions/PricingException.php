<?php

namespace App\Services\Pricing\Exceptions;

use RuntimeException;

/**
 * A pricing configuration that cannot produce a price.
 *
 * Deliberately not caught and turned into a zero markup anywhere. A misconfigured
 * ladder that quietly sells at cost loses money on every booking and looks exactly like
 * a correctly configured one that happens to take no margin.
 */
class PricingException extends RuntimeException {}
