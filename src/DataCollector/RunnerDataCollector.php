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
    private Runner $runner;

    public function __construct(Runner $runner)
    {
        $this->runner = $runner;
    }

    /**
     * Collect and format all runner profiler data.
     */
    private function doCollect(): array
    {
        $runner = $this->runner;

        if (!$runner instanceof ProfilerAware) {
            return [];
        }

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

        foreach ($runner->getProfiler()->all() as $result) {
            \assert($result instanceof ProfilerResult);

            if ($result instanceof QueryProfiler) {
                $ret['queries'][] = [
                    'sql' => null,// $rawSQL,
                    'params' => [], // $this->pruneNonScalarFrom($arguments),
                    'options' => [], // $this->pruneNonScalarFrom($options),
                    'prepared' => [], //$prepared,
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
        $this->doCollect($request, $response);
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
