<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * A wallet domain guard violation (insufficient funds, a request already
 * reviewed, approving your own request). Renders itself as a redirect-back with
 * errors, so controllers call the service without try/catch.
 *
 * Mirrors RbacException but keeps its own error bag key, so wallet failures do
 * not surface under an unrelated heading.
 */
class WalletException extends RuntimeException
{
    public function render(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withErrors(['wallet' => $this->getMessage()])->withInput();
    }
}
