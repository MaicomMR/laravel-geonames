<?php

namespace Nevadskiy\Geonames\Seeders;

use Illuminate\Support\LazyCollection;

/**
 * @mixin ModelSeeder
 */
trait UpdatesModelRecords
{
    /**
     * Get records for a daily update.
     */
    abstract protected function getRecordsForDailyUpdate(): iterable;

    /**
     * Update database using the dataset with daily modifications.
     */
    protected function dailyUpdate(): void
    {
        $updatable = [];

        // TODO: check if multiple iterations does not break lazy collection iterator.
        foreach ($this->getMappedRecordsForDailyUpdated()->chunk(1000) as $records) {
            $this->query()
                ->whereIn(self::SYNC_KEY, $records->keys()->all())
                ->toBase()
                ->update([self::SYNCED_AT => null]);

            $updatable = $updatable ?: $this->getUpdatableAttributes($records->first());

            $this->query()->upsert($records->all(), [self::SYNC_KEY], $updatable);
        }

        $this->deleteUnsyncedModels();
    }

    protected function getMappedRecordsForDailyUpdated(): LazyCollection
    {
        return LazyCollection::make(function () {
            foreach ($this->getRecordsForDailyUpdate() as $record) {
                yield $this->mapKey($record) => $record;
            }
        });
    }

    /**
     * Map the record key.
     */
    protected function mapKey(array $record): string
    {
        return $record['geonameid'];
    }

    /**
     * Get updatable attributes of the model.
     * TODO: fetch attributes from db table, not record.
     */
    protected function getUpdatableAttributes(array $record): array
    {
        $updatable = $this->updatable();

        if (! $this->isWildcardAttributes($updatable)) {
            return $updatable;
        }

        return collect(array_keys($record))
            ->diff(['id', self::SYNC_KEY, 'created_at'])
            ->concat([self::SYNCED_AT, 'updated_at'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Determine if the given attributes is a wildcard.
     */
    protected function isWildcardAttributes(array $attributes): bool
    {
        return count($attributes) === 1 && $attributes[0] === '*';
    }

    /**
     * Get the updatable attributes of the model.
     */
    protected function updatable(): array
    {
        return ['*'];
    }
}