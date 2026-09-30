<?php

namespace App\Domain\Bitcraft\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BitcraftSpacetimeSnapshotStore
{
    public function isEnabled(): bool
    {
        return (bool) config('services.bitcraft_spacetime.database_cache', true);
    }

    public function isReady(): bool
    {
        return $this->isEnabled()
            && Schema::hasTable('bitcraft_spacetime_snapshots')
            && Schema::hasTable('bitcraft_spacetime_rows');
    }

    public function import(array $snapshot): ?int
    {
        if (! $this->isReady() || ! is_array(data_get($snapshot, 'tables'))) {
            return null;
        }

        return DB::transaction(function () use ($snapshot): int {
            DB::table('bitcraft_spacetime_snapshots')
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'updated_at' => now(),
                ]);

            $snapshotId = (int) DB::table('bitcraft_spacetime_snapshots')->insertGetId([
                'source' => (string) data_get($snapshot, 'source', 'bitcraft-spacetimedb'),
                'host' => data_get($snapshot, 'host'),
                'database' => data_get($snapshot, 'database'),
                'generated_at' => $this->generatedAt(data_get($snapshot, 'generatedAt')),
                'generated_at_source' => data_get($snapshot, 'generatedAt'),
                'table_counts' => json_encode($this->tableCounts($snapshot), JSON_THROW_ON_ERROR),
                'is_current' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (data_get($snapshot, 'tables', []) as $tableName => $table) {
                $rows = data_get($table, 'rows', []);

                if (! is_array($rows) || $rows === []) {
                    continue;
                }

                $seenKeys = [];
                $records = [];

                foreach (array_values($rows) as $index => $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $records[] = [
                        'snapshot_id' => $snapshotId,
                        'table_name' => (string) $tableName,
                        'row_key' => $this->rowKey($row, $index, $seenKeys),
                        'row_data' => json_encode($row, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($records) >= 1000) {
                        DB::table('bitcraft_spacetime_rows')->insert($records);
                        $records = [];
                    }
                }

                if ($records !== []) {
                    DB::table('bitcraft_spacetime_rows')->insert($records);
                }
            }

            DB::table('bitcraft_spacetime_snapshots')
                ->where('id', '!=', $snapshotId)
                ->delete();

            return $snapshotId;
        });
    }

    public function currentMetadata(): ?array
    {
        $snapshot = $this->currentSnapshotRecord();

        if (! $snapshot) {
            return null;
        }

        return [
            'source' => $snapshot->source,
            'generatedAt' => $snapshot->generated_at_source ?: $this->generatedAtIso($snapshot->generated_at),
            'host' => $snapshot->host,
            'database' => $snapshot->database,
            'storage' => 'database',
            'tables' => $this->decodeJson($snapshot->table_counts),
        ];
    }

    public function tableRows(string $tableName): array
    {
        $snapshot = $this->currentSnapshotRecord();

        if (! $snapshot) {
            return [];
        }

        return DB::table('bitcraft_spacetime_rows')
            ->where('snapshot_id', $snapshot->id)
            ->where('table_name', $tableName)
            ->orderBy('id')
            ->pluck('row_data')
            ->map(fn (mixed $row): array => $this->decodeJson($row))
            ->all();
    }

    public function currentSnapshot(): ?array
    {
        $snapshot = $this->currentSnapshotRecord();

        if (! $snapshot) {
            return null;
        }

        $tables = [];

        DB::table('bitcraft_spacetime_rows')
            ->where('snapshot_id', $snapshot->id)
            ->orderBy('table_name')
            ->orderBy('id')
            ->select(['table_name', 'row_data'])
            ->chunk(1000, function ($rows) use (&$tables): void {
                foreach ($rows as $row) {
                    $tables[$row->table_name]['rows'][] = $this->decodeJson($row->row_data);
                }
            });

        foreach ($this->decodeJson($snapshot->table_counts) as $tableName => $count) {
            $tables[$tableName] = [
                'count' => (int) $count,
                'rows' => $tables[$tableName]['rows'] ?? [],
            ];
        }

        return [
            'source' => $snapshot->source,
            'generatedAt' => $snapshot->generated_at_source ?: $this->generatedAtIso($snapshot->generated_at),
            'host' => $snapshot->host,
            'database' => $snapshot->database,
            'storage' => 'database',
            'tables' => $tables,
        ];
    }

    private function currentSnapshotRecord(): ?object
    {
        if (! $this->isReady()) {
            return null;
        }

        return DB::table('bitcraft_spacetime_snapshots')
            ->where('is_current', true)
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }

    private function tableCounts(array $snapshot): array
    {
        return collect(data_get($snapshot, 'tables', []))
            ->map(fn (array $table): int => (int) data_get($table, 'count', count(data_get($table, 'rows', []))))
            ->all();
    }

    private function rowKey(array $row, int $index, array &$seenKeys): string
    {
        $baseKey = filled(data_get($row, 'id')) ? (string) data_get($row, 'id') : 'row-'.$index;
        $seenKeys[$baseKey] = ($seenKeys[$baseKey] ?? 0) + 1;

        if ($seenKeys[$baseKey] === 1) {
            return $baseKey;
        }

        return $baseKey.'#'.$seenKeys[$baseKey];
    }

    private function generatedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function generatedAtIso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toISOString();
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
