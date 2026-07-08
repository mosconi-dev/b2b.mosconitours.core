<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * A domain guard violation (e.g. removing the last administrator). It renders
 * itself as a redirect-back-with-errors for web requests, so controllers do not
 * need to try/catch — they call the service and Laravel renders this on failure.
 */
class RbacException extends RuntimeException
{
    public function render(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withErrors(['rbac' => $this->getMessage()])->withInput();
    }
}
