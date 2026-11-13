<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require that the authenticated user has confirmed their email address.
 *
 * Without this, a citizen who registers but never verifies their email
 * could bypass the redirect shown on the login page simply by posting
 * directly to a protected route. Email verification is what proves the
 * account is reachable by its owner, so it gates the citizen area.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Admins are not subject to citizen email verification.
        if ($user->isAdmin()) {
            return $next($request);
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()
                ->route('verification.notice')
                ->with('warning', 'Please verify your email address before continuing.');
        }

        return $next($request);
    }
}
