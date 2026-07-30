<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shared\Deletion\Metrics\MetricsCollectorInterface;
use SplObjectStorage;
use Throwable;

/**
 * Метрики операции удаления.
 *
 * Что снимаем:
 *   deletion.executions.total{root_class,status}      — сколько удалений и сколько из них успешных;
 *   deletion.duration.seconds{root_class,status}      — длительность всей операции;
 *   deletion.phase.duration.seconds{phase,root_class} — длительность фазы (detach / delete_children / delete_root),
 *                                                       чтобы сразу видеть, что именно тормозит;
 *   deletion.entities.affected.total{class,operation} — сколько строк реально затронуто;
 *   deletion.batch.size{class,operation}              — распределение размера батча.
 *
 */
final class MetricsDeletionMiddleware implements DeletionMiddlewareInterface
{
    private const EXECUTIONS = 'deletion.executions.total';
    private const DURATION = 'deletion.duration.seconds';
    private const PHASE_DURATION = 'deletion.phase.duration.seconds';
    private const ENTITIES = 'deletion.entities.affected.total';
    private const BATCH = 'deletion.batch.size';

    private const TOTAL = 'total';

    /**
     * Таймеры по root-объекту, а не одним полем: за время жизни процесса возможны вложенные
     * или последовательные execute(), и таймеры не должны перетирать друг друга.
     *
     * @var SplObjectStorage<object, array<string, int>>
     */
    private SplObjectStorage $timers;

    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->timers = new SplObjectStorage();
    }

    public function supports(string $entityClass): bool
    {
        return true;
    }

    public function beforeDeleteRoot(object $root): void
    {
        $this->start($root, self::TOTAL);
        $this->start($root, 'delete_root');
    }

    public function afterDeleteRoot(object $root): void
    {
        $rootClass = $this->shortName($root::class);

        $this->stop($root, 'delete_root', self::PHASE_DURATION, ['phase' => 'delete_root', 'root_class' => $rootClass]);
        $this->stop($root, self::TOTAL, self::DURATION, ['root_class' => $rootClass, 'status' => 'success']);

        $this->safe(fn () => $this->metrics->increment(self::EXECUTIONS, [
            'root_class' => $rootClass,
            'status' => 'success',
        ]));

        $this->timers->detach($root);
    }

    public function beforeDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $this->start($root, 'delete_children:' . $childClass);
        $this->observeBatch($childClass, $childIds, 'deleted');
    }

    public function afterDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $this->stop($root, 'delete_children:' . $childClass, self::PHASE_DURATION, [
            'phase' => 'delete_children',
            'root_class' => $this->shortName($root::class),
        ]);

        $this->countAffected($childClass, $childIds, 'deleted');
    }

    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $this->start($root, 'detach:' . $childClass);
        $this->observeBatch($childClass, $childIds, 'detached');
    }

    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $this->stop($root, 'detach:' . $childClass, self::PHASE_DURATION, [
            'phase' => 'detach',
            'root_class' => $this->shortName($root::class),
        ]);

        $this->countAffected($childClass, $childIds, 'detached');
    }

    public function recordFailure(object $root, Throwable $error): void
    {
        $rootClass = $this->shortName($root::class);

        $this->stop($root, self::TOTAL, self::DURATION, ['root_class' => $rootClass, 'status' => 'failure']);

        $this->safe(fn () => $this->metrics->increment(self::EXECUTIONS, [
            'root_class' => $rootClass,
            'status' => 'failure',
            'exception' => $this->shortName($error::class),
        ]));

        $this->timers->detach($root);
    }

    /** @param array<int|string> $ids */
    private function observeBatch(string $class, array $ids, string $operation): void
    {
        $this->safe(fn () => $this->metrics->observe(self::BATCH, (float) count($ids), [
            'class' => $this->shortName($class),
            'operation' => $operation,
        ]));
    }

    /** @param array<int|string> $ids */
    private function countAffected(string $class, array $ids, string $operation): void
    {
        $this->safe(fn () => $this->metrics->increment(self::ENTITIES, [
            'class' => $this->shortName($class),
            'operation' => $operation,
        ], count($ids)));
    }

    private function start(object $root, string $phase): void
    {
        $phases = $this->timers[$root] ?? [];
        $phases[$phase] = hrtime(true);
        $this->timers[$root] = $phases;
    }

    /** @param array<string, string|int> $labels */
    private function stop(object $root, string $phase, string $metric, array $labels): void
    {
        $phases = $this->timers[$root] ?? [];
        if (!isset($phases[$phase])) {
            return;
        }

        $elapsedNs = hrtime(true) - $phases[$phase];
        unset($phases[$phase]);
        $this->timers[$root] = $phases;

        $this->safe(fn () => $this->metrics->observe($metric, $elapsedNs / 1_000_000_000, $labels));
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->logger->warning('Deletion metrics failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
