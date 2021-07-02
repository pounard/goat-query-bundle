<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DataCollector;

use MakinaCorpus\Profiling\ProfilerContext;
use PhpCsFixer\Runner\Runner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use MakinaCorpus\Profiling\Profiler;

final class RunnerDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private iterable $runners;
    private ProfilerContext $profilerContext;

    public function __construct(iterable $runners, ProfilerContext $profilerContext)
    {
        $this->runners = $runners;
        $this->profilerContext = $profilerContext;
    }

    /**
     * Collect and format all runner profiler data.
     */
    private function doCollect(): array
    {
        $ret = [
            'exception' => 0, // @todo
            'execute_count' => 0, // @todo
            'execute_time' => 0, // @todo
            'perform_count' => 0, // @todo
            'perform_time' => 0, // @todo
            'prepare_count' => 0, // @todo
            'queries' => [],
            'query_count' => 0,
            'query_time' => 0,
            'total_count' => 0,
            'total_time' => 0,
            'transaction_commit_count' => 0, // @todo
            'transaction_count' => 0, // @todo
            'transaction_rollback_count' => 0, // @todo
            'transaction_time' => 0, // @todo
        ];

        $this->doCollectFromProfilerContext($ret);

        return $ret;
    }

    /**
     * Collect and format all runner profiler data for a single runner.
     */
    private function doCollectFromProfilerContext(array &$ret)
    {
        foreach ($this->profilerContext->getAllProfilers() as $profiler) {
            \assert($profiler instanceof Profiler);

            if ('goat-query' !== \substr($profiler->getName(), 0, 10)) {
                continue;
            }

            $attributes = $profiler->getAttributes();
            $elapsedTime = $profiler->getElapsedTime();

            $query = [
                'options' => [], // @todo deprecated
                'params' => $attributes['args'],
                'prepared' => [], // @todo deprecated
                'sql' => $attributes['sql'] ?? '<none collected>',
                'timers' => [],
                'total' => $elapsedTime,
            ];

            foreach ($profiler->getChildren() as $child) {
                \assert($child instanceof Profiler);
                $query['timers'][$child->getName()] = $child->getElapsedTime();
            }

            $ret['queries'][] = $query;
            $ret['query_count']++;
            $ret['query_time'] += $elapsedTime;
            $ret['total_count']++;
            $ret['total_time'] += $elapsedTime;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function collect(Request $request, Response $response, \Throwable $exception = null)
    {
        $this->data = $this->doCollect($request, $response);
    }

    /**
     * Get collected data.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get executed queries raw SQL.
     */
    public function getQueries(): array
    {
        return $this->data['queries'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function lateCollect()
    {
        return $this->doCollect();
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'goat_runner';
    }

    /**
     * {@inheritdoc}
     */
    public function reset()
    {
        $this->data = [];
    }
}
