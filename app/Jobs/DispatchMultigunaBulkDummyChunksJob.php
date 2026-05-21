<?php

namespace App\Jobs;

use Generator;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SplFileObject;

class DispatchMultigunaBulkDummyChunksJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 500;
    private const BATCH_ADD_SIZE = 20;

    public int $timeout = 0;
    public int $tries = 1;

    public function __construct(
        public readonly string $bulkId,
        public readonly string $filePath,
        public readonly string $disk = 'local',
        public readonly ?string $originalName = null,
    ) {
        $this->onQueue('bulk-multiguna');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $startedAt = microtime(true);
        $absolutePath = Storage::disk($this->disk)->path($this->filePath);

        if (! is_file($absolutePath)) {
            throw new RuntimeException("Bulk dummy file not found: {$this->filePath}");
        }

        Log::info('Dummy bulk multiguna cursor started', [
            'bulk_id' => $this->bulkId,
            'file' => $this->originalName,
            'path' => $this->filePath,
            'chunk_size' => self::CHUNK_SIZE,
            'database_write' => false,
        ]);

        $chunk = [];
        $pendingJobs = [];
        $chunkNumber = 1;
        $totalRows = 0;
        $totalChunkJobs = 0;

        foreach ($this->rowCursor($absolutePath) as $row) {
            if ($this->batch()?->cancelled()) {
                return;
            }

            $chunk[] = $row;
            $totalRows++;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $pendingJobs[] = new ProcessMultigunaBulkDummyChunkJob(
                    $this->bulkId,
                    $chunkNumber,
                    $chunk,
                );

                $chunk = [];
                $chunkNumber++;
                $this->flushPendingJobs($pendingJobs, $totalChunkJobs);
            }
        }

        if ($chunk !== []) {
            $pendingJobs[] = new ProcessMultigunaBulkDummyChunkJob(
                $this->bulkId,
                $chunkNumber,
                $chunk,
            );
        }

        $this->flushPendingJobs($pendingJobs, $totalChunkJobs, true);

        Log::info('Dummy bulk multiguna cursor finished', [
            'bulk_id' => $this->bulkId,
            'rows' => $totalRows,
            'chunk_jobs' => $totalChunkJobs,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
            'database_write' => false,
        ]);
    }

    /**
     * Cursor CSV supaya file besar tidak dibaca sekaligus ke memory.
     */
    private function rowCursor(string $path): Generator
    {
        $file = new SplFileObject($path, 'rb');
        $delimiter = null;
        $headers = null;
        $lineNumber = 0;

        while (! $file->eof()) {
            $line = $file->fgets();
            $lineNumber++;

            if ($line === false || trim($line) === '') {
                continue;
            }

            $line = $this->removeUtf8Bom($line);
            $delimiter = $this->detectDelimiter($line);
            $headers = $this->normalizeHeaders(str_getcsv($line, $delimiter));
            break;
        }

        if ($headers === null || $headers === []) {
            return;
        }

        while (! $file->eof()) {
            $row = $file->fgetcsv($delimiter);
            $lineNumber++;

            if ($row === false || $this->isEmptyCsvRow($row)) {
                continue;
            }

            yield [
                'line' => $lineNumber,
                'data' => $this->combineRow($headers, $row),
            ];
        }
    }

    private function flushPendingJobs(array &$pendingJobs, int &$totalChunkJobs, bool $force = false): void
    {
        if ($pendingJobs === []) {
            return;
        }

        if (! $force && count($pendingJobs) < self::BATCH_ADD_SIZE) {
            return;
        }

        $this->batch()?->add($pendingJobs);
        $totalChunkJobs += count($pendingJobs);
        $pendingJobs = [];
    }

    private function detectDelimiter(string $line): string
    {
        $delimiters = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];

        foreach ($delimiters as $delimiter => $count) {
            $delimiters[$delimiter] = substr_count($line, $delimiter);
        }

        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        $seen = [];

        foreach ($headers as $index => $header) {
            $name = trim($this->removeUtf8Bom((string) $header));
            $name = $name !== '' ? $name : 'column_' . ($index + 1);

            if (isset($seen[$name])) {
                $seen[$name]++;
                $name .= '_' . $seen[$name];
            } else {
                $seen[$name] = 1;
            }

            $normalized[] = $name;
        }

        return $normalized;
    }

    private function combineRow(array $headers, array $row): array
    {
        $normalizedRow = array_pad($row, count($headers), null);
        $data = [];

        foreach ($headers as $index => $header) {
            $data[$header] = isset($normalizedRow[$index])
                ? trim((string) $normalizedRow[$index])
                : null;
        }

        if (count($row) > count($headers)) {
            foreach (array_slice($row, count($headers)) as $index => $value) {
                $data['extra_column_' . ($index + 1)] = trim((string) $value);
            }
        }

        return $data;
    }

    private function isEmptyCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function removeUtf8Bom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }
}
