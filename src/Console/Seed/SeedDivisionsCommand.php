<?php

namespace Nevadskiy\Geonames\Console\Seed;

use Generator;
use Illuminate\Console\Command;
use Nevadskiy\Geonames\Models\Division;
use Nevadskiy\Geonames\Parsers\GeonamesParser;
use Nevadskiy\Geonames\Seeders\DivisionSeeder;
use RuntimeException;

class SeedDivisionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geonames:seed:divisions {--source=} {--truncate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed divisions into the database.';

    /**
     * Execute the console command.
     */
    public function handle(GeonamesParser $parser, DivisionSeeder $seeder): void
    {
        $this->info('Start seeding divisions. It may take some time.');

        $this->truncate();

        $this->prepareLongRunningCommand();
        $this->setUpProgressBar($parser);

        foreach ($this->divisions($parser) as $id => $division) {
            $seeder->seed($division, $id);
        }

        $this->info('Divisions have been successfully seeded.');
    }

    /**
     * Truncate a table if option is specified.
     */
    private function truncate(): void
    {
        // TODO: add production warning

        if ($this->option('truncate')) {
            Division::query()->truncate();
            $this->info('Divisions table has been truncated.');
        }
    }

    /**
     * If app has registered flare package which come out of the box with laravel, you may encounter a memory leak.
     */
    private function prepareLongRunningCommand(): void
    {
        if (config()->has('flare')) {
            config(['flare.reporting.report_query_bindings' => false]);
        }
    }

    /**
     * TODO: extract into parser decorator ProgressBarParser.php
     * Set up the progress bar.
     */
    private function setUpProgressBar(GeonamesParser $parser, int $step = 1000): void
    {
        $progress = $this->output->createProgressBar();

        $parser->enableCountingLines()
            ->onReady(static function (int $linesCount) use ($progress) {
                $progress->start($linesCount);
            })
            ->onEach(static function () use ($progress, $step) {
                $progress->advance($step);
            }, $step)
            ->onFinish(function () use ($progress) {
                $progress->finish();
                $this->output->newLine();
            });
    }

    /**
     * Get divisions for seeding.
     */
    private function divisions(GeonamesParser $parser): Generator
    {
        return $parser->forEach($this->getDivisionsSourcePath());
    }

    /**
     * Get divisions source path.
     */
    protected function getDivisionsSourcePath(): string
    {
        if ($this->hasOptionSourcePath()) {
            return $this->getOptionSourcePath();
        }

        return config('geonames.directory') . DIRECTORY_SEPARATOR . config('geonames.files.all_countries');
    }

    /**
     * Determine whether the command has given source option.
     *
     * @return bool
     */
    protected function hasOptionSourcePath(): bool
    {
        return (bool) $this->option('source');
    }

    /**
     * Get source path from the command option.
     *
     * @return string
     */
    public function getOptionSourcePath(): string
    {
        $path = base_path($this->option('source'));

        if (! file_exists($path)) {
            throw new RuntimeException("File does not exist {$path}.");
        }

        return $path;
    }
}