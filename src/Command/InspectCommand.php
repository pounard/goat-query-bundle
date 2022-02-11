<?php

declare (strict_types=1);

namespace Goat\Query\Symfony\Command;

use Goat\Runner\Runner;
use Goat\Schema\ColumnMetadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InspectCommand extends Command
{
    protected static $defaultName = 'goat-query:inspect';
    protected static $defaultDescription = "Introspect database schema.";

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
            ->addOption('table', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "List columns of table(s).")
            ->addOption('schema', 's', InputOption::VALUE_OPTIONAL, "Default schema is 'public' if not specified.")
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schema = $input->getOption('schema');
        $tables = $input->getOption('table');

        if ($tables) {
            if (!$schema) {
                if ($output->isVerbose()) {
                    $output->writeln("No schema specified, using 'public'. Use --schema=SCHEMA to query a specific schema.");
                }
                $schema = 'public';
            }
            $this->doListTableColumns($output, $schema, $tables);
        } else if ($schema) {
            $this->doListSchemaTables($output, $schema);
        } else {
            $this->doListSchemas($output);
        }

        return 0;
    }

    private function doListSchemas(OutputInterface $output): void
    {
        $schemaIntrospector = $this->runnner->getPlatform()->createSchemaIntrospector($this->runnner);

        if ($output->isVerbose()) {
            $output->writeln('<comment>' . "Listing all schemas in database." . '</comment>');
        }

        foreach ($schemaIntrospector->listSchemas() as $schema) {
            $output->writeln($schema);
        }
    }

    private function doListSchemaTables(OutputInterface $output, string $schema): void
    {
        $schemaIntrospector = $this->runnner->getPlatform()->createSchemaIntrospector($this->runnner);

        if ($output->isVerbose()) {
            $output->writeln('<comment>' . \sprintf("Listing tables from schema '%s'.", $schema) . '</comment>');
        }

        foreach ($schemaIntrospector->listTables($schema) as $table) {
            $output->writeln($table);
        }
    }

    private function doListTableColumns(OutputInterface $output, string $schema, array $tables): void
    {
        $schemaIntrospector = $this->runnner->getPlatform()->createSchemaIntrospector($this->runnner);

        foreach ($tables as $name) {
            if (!$schemaIntrospector->tableExists($schema, $name)) {
                $output->writeln('<error>' . \sprintf("Table does not exists: '%s'", $name) . '</error>');
                continue;
            }

            $output->writeln('<comment>' . \sprintf("Table '%s'", $name) . '</comment>');

            $outputTable = new Table($output);
            $outputTable->setHeaders([
                'column',
                'type',
                'nullable',
                'pkey',
            ]);

            $table = $schemaIntrospector->fetchTableMetadata($schema, $name);
            $primaryKey = $table->getPrimaryKey();

            foreach ($table->getColumns() as $column) {
                \assert($column instanceof ColumnMetadata);

                $outputTable->addRow([
                    $column->getName(),
                    $column->getType(),
                    $column->isNullable() ? 'yes' : 'no',
                    $primaryKey->contains($column->getName()) ? 'yes' : '',
                ]);
            }

            $outputTable->render();
        }
    }
}
