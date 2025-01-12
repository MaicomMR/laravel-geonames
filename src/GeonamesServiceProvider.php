<?php

namespace Nevadskiy\Geonames;

use Facade\Ignition\QueryRecorder\QueryRecorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Nova;
use Nevadskiy\Geonames\Events\GeonamesCommandReady;
use Nevadskiy\Geonames\Listeners\DisableIgnitionBindings;
use Nevadskiy\Geonames\Nova as Resources;
use Nevadskiy\Geonames\Parsers\FileParser;
use Nevadskiy\Geonames\Parsers\Parser;
use Nevadskiy\Geonames\Parsers\ProgressParser;
use Nevadskiy\Geonames\Services\SupplyService;
use Nevadskiy\Geonames\Suppliers\Translations\CompositeTranslationMapper;
use Nevadskiy\Geonames\Suppliers\Translations\TranslationMapper;
use Nevadskiy\Geonames\Support\Downloader\BaseDownloader;
use Nevadskiy\Geonames\Support\Downloader\ConsoleDownloader;
use Nevadskiy\Geonames\Support\Downloader\Downloader;
use Nevadskiy\Geonames\Support\Downloader\UnzipperDownloader;
use Nevadskiy\Geonames\Support\FileReader\BaseFileReader;
use Nevadskiy\Geonames\Support\FileReader\FileReader;
use Nevadskiy\Geonames\Support\Logger\ConsoleLogger;
use Nevadskiy\Geonames\Support\Output\OutputFactory;
use Nevadskiy\Translatable\Translatable;
use Psr\Log\LoggerInterface;

class GeonamesServiceProvider extends ServiceProvider
{
    /**
     * The package's name.
     */
    protected const PACKAGE = 'geonames';

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->registerConfig();
        $this->registerGeonames();
        $this->registerLogger();
        $this->registerDownloader();
        $this->registerFileReader();
        $this->registerParser();
        $this->registerSuppliers();
        $this->registerTranslationMapper();
        $this->registerIgnitionFixer();
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->bootCommands();
        $this->bootMigrations();
        $this->bootTranslatableMigrations();
        $this->bootNovaResources();
        $this->publishConfig();
        $this->publishMigrations();
    }

    /**
     * Register any package configurations.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/geonames.php', self::PACKAGE);
    }

    /**
     * Register the geonames.
     */
    protected function registerGeonames(): void
    {
        $this->app->singleton(Geonames::class);
    }

    /**
     * Register any package logger.
     */
    protected function registerLogger(): void
    {
        $this->app->singleton(ConsoleLogger::class, function () {
            return new ConsoleLogger(OutputFactory::make());
        });

        if ($this->app->runningInConsole()) {
            $this->app->when(BaseDownloader::class)
                ->needs(LoggerInterface::class)
                ->give(ConsoleLogger::class);

            $this->app->when(SupplyService::class)
                ->needs(LoggerInterface::class)
                ->give(ConsoleLogger::class);
        }
    }

    /**
     * Register any package downloader.
     */
    protected function registerDownloader(): void
    {
        $this->app->bind(Downloader::class, BaseDownloader::class);

        $this->app->extend(Downloader::class, function (Downloader $downloader) {
            return $this->app->make(UnzipperDownloader::class, ['downloader' => $downloader]);
        });

        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $this->app->extend(Downloader::class, function (Downloader $downloader) {
                return new ConsoleDownloader($downloader, OutputFactory::make());
            });
        }
    }

    /**
     * Register any package file reader.
     */
    protected function registerFileReader(): void
    {
        $this->app->bind(FileReader::class, BaseFileReader::class);
    }

    /**
     * Register any package resource parser.
     */
    protected function registerParser(): void
    {
        $this->app->bind(Parser::class, FileParser::class);

        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $this->app->extend(Parser::class, function (Parser $parser) {
                return new ProgressParser($parser, OutputFactory::make());
            });
        }
    }

    /**
     * Register any package suppliers.
     */
    protected function registerSuppliers(): void
    {
        foreach ($this->app['config']['geonames']['suppliers'] as $supplier => $implementation) {
            $this->app->bind($supplier, $implementation);
        }
    }

    /**
     * Register any package translation mapper.
     */
    protected function registerTranslationMapper(): void
    {
        $this->app->bind(TranslationMapper::class, function () {
            $mappers = collect([
                'continent' => Suppliers\Translations\ContinentTranslationMapper::class,
                'country' => Suppliers\Translations\CountryTranslationMapper::class,
                'division' => Suppliers\Translations\DivisionTranslationMapper::class,
                'city' => Suppliers\Translations\CityTranslationMapper::class,
            ])
                ->only(array_keys($this->app->make(Geonames::class)->modelClasses()))
                ->map(function (string $mapper) {
                    return $this->app->make($mapper);
                })
                ->toArray();

            return new CompositeTranslationMapper($mappers);
        });
    }

    /**
     * Register ignition memory limit fixer.
     */
    protected function registerIgnitionFixer(): void
    {
        if (class_exists(QueryRecorder::class)) {
            $this->app[Dispatcher::class]->listen(GeonamesCommandReady::class, DisableIgnitionBindings::class);
        }
    }

    /**
     * Boot any package commands.
     */
    protected function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Seed\GeonamesSeedCommand::class,

                Console\Insert\InsertCommand::class,
                Console\Insert\InsertTranslationsCommand::class,
                Console\Update\UpdateCommand::class,
                Console\Update\UpdateTranslationsCommand::class,
            ]);
        }
    }

    /**
     * Boot any package migrations.
     */
    protected function bootMigrations(): void
    {
        $geonames = $this->app[Geonames::class];

        if ($this->app->runningInConsole() && $geonames->shouldUseDefaultMigrations()) {
            if ($geonames->shouldSupplyContinents()) {
                $this->loadMigrationsFrom($this->migration('2020_06_06_100000_create_continents_table.php'));
            }

            if ($geonames->shouldSupplyCountries()) {
                $this->loadMigrationsFrom($this->migration('2020_06_06_200000_create_countries_table.php'));
            }

            if ($geonames->shouldSupplyDivisions()) {
                $this->loadMigrationsFrom($this->migration('2020_06_06_300000_create_divisions_table.php'));
            }

            if ($geonames->shouldSupplyCities()) {
                $this->loadMigrationsFrom($this->migration('2020_06_06_400000_create_cities_table.php'));
            }
        }
    }

    /**
     * Boot any translatable migrations.
     */
    public function bootTranslatableMigrations(): void
    {
        $this->callAfterResolving(Translatable::class, function (Translatable $translatable) {
            if (! $this->app[Geonames::class]->shouldSupplyTranslations()) {
                $translatable->ignoreMigrations();
            }
        });
    }

    /**
     * Boot any package nova resources.
     */
    protected function bootNovaResources(): void
    {
        if ($this->app[Geonames::class]->shouldBootNovaResources()) {
            Nova::resources([
                Resources\Continent::class,
                Resources\Country::class,
                Resources\Division::class,
                Resources\City::class,
            ]);
        }
    }

    /**
     * Publish any package configurations.
     */
    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/geonames.php' => config_path('geonames.php'),
        ], self::PACKAGE.'-config');
    }

    /**
     * Publish any package migrations.
     */
    protected function publishMigrations(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], self::PACKAGE.'-migrations');
    }

    /**
     * Get the migration file with the given file name.
     */
    protected function migration(string $file): string
    {
        return __DIR__."/../database/migrations/{$file}";
    }
}
