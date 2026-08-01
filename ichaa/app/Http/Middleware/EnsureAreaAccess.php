<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAreaAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $area): Response
    {
        if ($request->user()?->canAccessArea($area)) {
            return $next($request);
        }

        return redirect()
            ->route('home')
            ->with('error', 'You do not have access to that Datacrypt section.');
    }
}
