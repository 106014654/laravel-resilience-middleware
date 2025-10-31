<?php

namespace OneLap\LaravelResilienceMiddleware\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * CircuitBreaker Facade
 * 
 * @method static array getCircuitStats(string|null $service = null)
 * @method static string getCircuitState(string $circuitKey)
 * @method static void setCircuitState(string $circuitKey, string $state)
 * @method static void recordSuccess(string $circuitKey, string $currentState, int $successThreshold)
 * @method static void recordFailure(string $circuitKey, string $currentState, int $failureThreshold)
 */
class CircuitBreaker extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'circuit.breaker';
    }
}
