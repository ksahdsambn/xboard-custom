<?php

namespace Plugin\MobileApp\Support;

use Plugin\MobileApp\Exceptions\MobileApiException;
use Throwable;

final class DownstreamRetryPolicy
{
    public const MAX_ATTEMPTS = 3;

    public const BACKOFF_MS = [0, 50, 100];

    /** @var list<array{op:string,attempt:int,ok:bool}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function run(string $op, callable $fn): mixed
    {
        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $result = $fn();
                self::$calls[] = ['op' => $op, 'attempt' => $attempt, 'ok' => true];
                return $result;
            } catch (MobileApiException $exception) {
                self::$calls[] = ['op' => $op, 'attempt' => $attempt, 'ok' => false];
                throw $exception;
            } catch (Throwable $exception) {
                $last = $exception;
                self::$calls[] = ['op' => $op, 'attempt' => $attempt, 'ok' => false];
                if ($attempt >= self::MAX_ATTEMPTS) {
                    MobileLogRedactor::error('downstream_exhausted', [
                        'op' => $op,
                        'attempts' => $attempt,
                        'timeoutSeconds' => MobileSecurityGuard::TIMEOUT_SECONDS,
                    ]);
                    throw new MobileApiException('DOWNSTREAM_UNAVAILABLE', 503);
                }
                $waitMs = self::BACKOFF_MS[$attempt] ?? self::BACKOFF_MS[count(self::BACKOFF_MS) - 1];
                if ($waitMs > 0) {
                    usleep($waitMs * 1000);
                }
            }
        }
        throw $last ?? new MobileApiException('DOWNSTREAM_UNAVAILABLE', 503);
    }

    public static function attemptsFor(string $op): int
    {
        return count(array_filter(self::$calls, static fn (array $row): bool => $row['op'] === $op));
    }
}
