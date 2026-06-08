<?php

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;

/**
 * Get the available auth instance.
 *
 * @param  string|null  $guard
 * @return \Illuminate\Contracts\Auth\Guard
 */
function auth($guard = null): Guard
{
    return app(AuthFactory::class)->guard($guard);
}
