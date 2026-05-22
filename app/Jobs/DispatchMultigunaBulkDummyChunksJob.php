<?php

namespace App\Jobs;

use App\Support\SpreadsheetRowRangeReadFilter;
use Generator;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
                $pendingJobs[] = $this->makeChunkJob($chunkNumber, $chunk);

                $chunk = [];
                $chunkNumber++;
                $this->flushPendingJobs($pendingJobs, $totalChunkJobs);
            }
        }

        if ($chunk !== []) {
            $pendingJobs[] = $this->makeChunkJob($chunkNumber, $chunk);
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
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            yield from $this->spreadsheetRowCursor($path);

            return;
        }

        yield from $this->csvRowCursor($path);
    }

    /**
     * Cursor CSV supaya file besar tidak dibaca sekaligus ke memory.
     */
    private function csvRowCursor(string $path): Generator
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

    /**
     * Cursor XLS/XLSX per range baris supaya spreadsheet besar tetap diproses bertahap.
     */
    private function spreadsheetRowCursor(string $path): Generator
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $worksheetInfo = $reader->listWorksheetInfo($path)[0] ?? null;

        if ($worksheetInfo === null) {
            return;
        }

        $totalRows = (int) ($worksheetInfo['totalRows'] ?? 0);
        $lastColumn = (string) ($worksheetInfo['lastColumnLetter'] ?? 'A');

        if ($totalRows < 1) {
            return;
        }

        $reader->setReadFilter(new SpreadsheetRowRangeReadFilter(1, 1));
        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $headerRow = $worksheet->rangeToArray("A1:{$lastColumn}1", null, true, false)[0] ?? [];
        $headers = $this->normalizeHeaders($headerRow);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if ($headers === []) {
            return;
        }

        for ($startRow = 2; $startRow <= $totalRows; $startRow += self::CHUNK_SIZE) {
            $endRow = min($startRow + self::CHUNK_SIZE - 1, $totalRows);

            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new SpreadsheetRowRangeReadFilter($startRow, $endRow));

            $spreadsheet = $reader->load($path);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->rangeToArray("A{$startRow}:{$lastColumn}{$endRow}", null, true, false);

            foreach ($rows as $offset => $row) {
                if ($this->isEmptyCsvRow($row)) {
                    continue;
                }

                yield [
                    'line' => $startRow + $offset,
                    'data' => $this->combineRow($headers, $row),
                ];
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
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

    private function makeChunkJob(int $chunkNumber, array $rows): ProcessMultigunaBulkDummyChunkJob
    {
        $chunkPath = sprintf(
            'bulk-dummy/multiguna/chunks/%s/chunk-%05d.json',
            $this->bulkId,
            $chunkNumber,
        );

        Storage::disk($this->disk)->put(
            $chunkPath,
            json_encode($rows, JSON_THROW_ON_ERROR),
        );

        return new ProcessMultigunaBulkDummyChunkJob(
            $this->bulkId,
            $chunkNumber,
            $chunkPath,
            $this->disk,
        );
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
            $name = $name !== '' ? $name : 'column_'.($index + 1);

            if (isset($seen[$name])) {
                $seen[$name]++;
                $name .= '_'.$seen[$name];
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
                $data['extra_column_'.($index + 1)] = trim((string) $value);
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
