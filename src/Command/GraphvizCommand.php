<?php

declare (strict_types=1);

namespace Goat\Query\Symfony\Command;

use Goat\Runner\Runner;
use Goat\Schema\Browser\SchemaBrowser;
use Goat\Schema\Tools\GraphvizVisitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GraphvizCommand extends Command
{
    protected static $defaultName = 'goat-query:graphviz';
    protected static $defaultDescription = "Generate graphviz source file which represents the complete database schema.";

    private Runner $runner;

    public function __construct(Runner $runner)
    {
        parent::__construct();

        $this->runner = $runner;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addOption('schema', 's', InputOption::VALUE_OPTIONAL, "Default schema is 'public' if not specified.")
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemaIntrospector = $this->runner->getPlatform()->createSchemaIntrospector($this->runner);

        $schema = $input->getOption('schema') ?? 'public';

        $visitor = new GraphvizVisitor();

        (new SchemaBrowser($schemaIntrospector))
            ->visitor($visitor)
            ->browseSchema($schema, SchemaBrowser::MODE_RELATION_NORMAL)
        ;

        $output->writeln($visitor->getOutput());

        return 0;
    }
}
