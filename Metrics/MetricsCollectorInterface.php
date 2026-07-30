<?php

declare(strict_types=1);

namespace Shared\Deletion\Metrics;

/**
 * Минимальный контракт, чтобы не привязывать модуль к конкретному SDK
 */
interface MetricsCollectorInterface
{
    /** @param array<string, string|int> $labels */
    public function increment(string $metric, array $labels = [], int $by = 1): void;

    /** @param array<string, string|int> $labels */
    public function observe(string $metric, float $value, array $labels = []): void;
}
