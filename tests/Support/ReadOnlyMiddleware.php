<?php

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;

class ReadOnlyMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort(423, 'Agent profiles are read-only here.');
    }
}
