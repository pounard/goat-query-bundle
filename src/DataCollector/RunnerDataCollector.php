<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DataCollector;

use Goat\Driver\Instrumentation\ProfilerAware;
use Goat\Driver\Instrumentation\ProfilerResult;
use Goat\Driver\Instrumentation\QueryProfiler;
use Goat\Runner\Runner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

final class RunnerDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private iterable $runners;

    public function __construct(iterable $runners)
    {
        $this->runners = $runners;
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

        foreach ($this->runners as $runner) {
            $this->doCollectRunner($runner, $ret);
        }

        return $ret;
    }

    /**
     * Collect and format all runner profiler data for a single runner.
     */
    private function doCollectRunner(Runner $runner, array &$ret)
    {
        if (!$runner instanceof ProfilerAware) {
            return;
        }

        foreach ($runner->getProfiler()->all() as $result) {
            \assert($result instanceof ProfilerResult);

            if ($result instanceof QueryProfiler) {
                $ret['queries'][] = [
                    'options' => [], // $this->pruneNonScalarFrom($options),
                    'params' => $result->getSqlArguments() ?? [],
                    'prepared' => [], //$prepared,
                    'sql' => $result->getSqlQuery(),
                    'timers' => $result->getAll(),
                    'total' => $result->getTotalTime(),
                ];
                $ret['query_count']++;
                $ret['query_time'] += $result->getTotalTime();
            }

            $ret['total_count']++;
            $ret['total_time'] += $result->getTotalTime();
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
