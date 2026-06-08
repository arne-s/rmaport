<?php

namespace App\Helpers;

use Exception;

class RegexHelper
{
    /**
     * Match a regex with a timeout mechanism to prevent catastrophic backtracking / ReDoS attacks.
     * https://docs.mobb.ai/mobb-user-docs/fixing-guides/regex-missing-timeout-fix-guide
     * @param string $pattern
     * @param string $input
     * @param int $timeout
     * @return array|false
     * @throws Exception
     */
    public static function matchWithTimeout($pattern, $input, $timeout = 30)
    {
        $previousLimit = ini_get('max_execution_time');
        set_time_limit($timeout);

        try {
            $startTime = microtime(true);
            $matches = [];
            $result = preg_match($pattern, $input, $matches);

            if ((microtime(true) - $startTime) >= $timeout) {
                throw new Exception('Regex timeout exceeded for pattern: ' . $pattern);
            }

            return $matches;
        } finally {
            set_time_limit($previousLimit);
        }
    }

    /**
     * Match an email address against a wildcard pattern (e.g. *@domain.com, sales*@example.org).
     *
     * @param string $email The email address to check.
     * @param string $pattern The wildcard pattern (supports * as any chars).
     * @return bool
     */
    public static function matchesEmailWildcard(string $email, string $pattern): bool
    {
        // Escape regex special chars except *
        $escaped = preg_quote($pattern, '/');
        // Replace * with .*
        $regex = '/^' . str_replace('\*', '.*', $escaped) . '$/i';
        return (bool) preg_match($regex, $email);
    }
}