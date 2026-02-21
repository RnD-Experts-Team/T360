<?php

namespace App\Services\Acceptance;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class RejectionImportValidationService
{
    protected array $results = [
        'valid'            => [],
        'invalid'          => [],
        'summary'          => ['total' => 0, 'valid' => 0, 'invalid' => 0],
        'headers'          => [],
        'expected_headers' => [],
    ];

    // ─────────────────────────────────────────────────────────
    // SHARED HELPERS
    // ─────────────────────────────────────────────────────────

    private function detectDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) return ',';
        $firstLine = fgets($handle);
        fclose($handle);
        if ($firstLine === false) return ',';

        $delimiters = [
            ','  => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
            ';'  => substr_count($firstLine, ';'),
            '|'  => substr_count($firstLine, '|'),
        ];
        arsort($delimiters);
        return array_key_first($delimiters);
    }

    private function sanitizeHeaders(array $headers): array
    {
        if (!empty($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        $headers = array_map(fn($h) => trim((string) $h), $headers);
        while (!empty($headers) && end($headers) === '') {
            array_pop($headers);
        }
        return $headers;
    }

    private function sanitizeRow(array $row, int $expectedCount): array
    {
        $row = array_map(fn($v) => is_string($v) ? trim($v) : $v, $row);
        while (count($row) > $expectedCount && end($row) === '') {
            array_pop($row);
        }
        return $row;
    }

    private function getRawPreview(array $row, array $headers): array
    {
        $out = [];
        foreach (array_slice($headers, 0, 3) as $i => $h) {
            $val = isset($row[$i]) ? (string) $row[$i] : '';
            if (strlen($val) > 30) $val = substr($val, 0, 30) . '...';
            $out[] = [
                'key'   => $h,
                'label' => ucwords(str_replace('_', ' ', (string) $h)),
                'value' => $val,
            ];
        }
        if (empty($out)) {
            $out[] = ['key' => 'row', 'label' => 'Row', 'value' => '(empty)'];
        }
        return $out;
    }

    /**
     * Mirrors normalizeLoadRejectionBucket() in RejectionImportExportService.
     * Returns the DB enum string if the raw value is recognizable, null otherwise.
     */
    private function normalizeLoadBucket(string $raw): ?string
    {
        $normalized = strtolower(trim($raw));

        // Exact DB enum passthrough (re-import safe)
        if ($normalized === 'rejected_after_start_time')               return 'rejected_after_start_time';
        if ($normalized === 'rejected_0_6_hours_before_start_time')    return 'rejected_0_6_hours_before_start_time';
        if ($normalized === 'rejected_6_plus_hours_before_start_time') return 'rejected_6_plus_hours_before_start_time';

        // "After start time"
        if (str_contains($normalized, 'after start')) return 'rejected_after_start_time';

        // "0-6 hours" — must check before 6+ to avoid false match
        if (preg_match('/0\s*[-–]\s*6/', $normalized)) return 'rejected_0_6_hours_before_start_time';

        // "6+ hours"
        if (preg_match('/6\s*\+/', $normalized) || str_contains($normalized, '6 plus') || str_contains($normalized, '6plus')) {
            return 'rejected_6_plus_hours_before_start_time';
        }

        return null;
    }

    public function generateErrorReport(array $invalidRows): string
    {
        $fileName = 'rejections_import_errors_' . date('Y-m-d_His') . '.csv';
        $dir      = 'temp-imports';
        Storage::makeDirectory($dir);
        $path = $dir . '/' . $fileName;
        $full = Storage::path($path);

        $file = fopen($full, 'w');
        fputcsv($file, ['Row Number', 'Preview', 'Errors', 'Warnings']);

        foreach ($invalidRows as $row) {
            $previewString = '—';
            if (is_array($row['preview'] ?? null)) {
                $parts = [];
                foreach ($row['preview'] as $p) {
                    $label = $p['label'] ?? '';
                    $val   = $p['value'] ?? '';
                    if ($label !== '' && $val !== '') $parts[] = "{$label}: {$val}";
                }
                $previewString = !empty($parts) ? implode(' | ', $parts) : '—';
            } elseif (isset($row['preview'])) {
                $previewString = (string) $row['preview'];
            }

            fputcsv($file, [
                $row['rowNumber'] ?? '—',
                $previewString,
                !empty($row['errors'])   ? implode('; ', $row['errors'])   : '—',
                !empty($row['warnings']) ? implode('; ', $row['warnings']) : '—',
            ]);
        }

        fclose($file);
        return $full;
    }

    // ─────────────────────────────────────────────────────────
    // ADVANCED BLOCK
    // ─────────────────────────────────────────────────────────

    public function validateAdvancedBlockCsv($file, ?int $tenantId = null): array
    {
        $this->results = [
            'valid'            => [],
            'invalid'          => [],
            'summary'          => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'headers'          => [],
            'expected_headers' => [],
        ];

        $filePath  = $file->getRealPath();
        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if (!$handle) throw new \Exception('Unable to open CSV file.');

        $isSuperAdmin = Auth::user()->tenant_id === null;

        $expectedHeaders = [
            'Advance block rejection ID',
            'Week',
            'Week Start Date',
            'Week End Date',
            'Start time',
            'Driver Type',
            'Virtual Tractor ID',
            'Expected blocks',
            'Tendered blocks',
            'Impacted blocks',
            'Reason(s)',
        ];

        $this->results['expected_headers'] = $expectedHeaders;

        $rawHeader = fgetcsv($handle, 0, $delimiter);
        if ($rawHeader === false) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV appears to be empty or unreadable.'];
        }

        $headerRow = $this->sanitizeHeaders($rawHeader);
        $this->results['headers'] = $headerRow;

        if (count($headerRow) !== count($expectedHeaders)) {
            fclose($handle);
            return [
                ...$this->results,
                'header_error' => 'Headers do not match. Expected ' . count($expectedHeaders) . ' columns, got ' . count($headerRow)
                    . '. Detected delimiter: "' . addcslashes($delimiter, "\t") . '".',
            ];
        }

        $normalizedIncoming = array_map(fn($h) => strtolower(trim($h)), $headerRow);
        $normalizedExpected  = array_map(fn($h) => strtolower(trim($h)), $expectedHeaders);

        if ($normalizedIncoming !== $normalizedExpected) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV header names/order do not match expected template.'];
        }

        if ($isSuperAdmin && !$tenantId) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'Tenant must be selected for super admin imports.'];
        }

        $rowNumber = 1;
        while (($rawRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if (empty(array_filter($rawRow, fn($v) => trim((string)$v) !== ''))) continue;

            $this->results['summary']['total']++;
            $row    = $this->sanitizeRow($rawRow, count($expectedHeaders));
            $result = $this->validateAdvancedBlockRow($row, $expectedHeaders, $rowNumber);

            if ($result['isValid']) {
                $this->results['valid'][]  = $result;
                $this->results['summary']['valid']++;
            } else {
                $this->results['invalid'][] = $result;
                $this->results['summary']['invalid']++;
            }
        }

        fclose($handle);
        return $this->results;
    }

    protected function validateAdvancedBlockRow(array $row, array $headers, int $rowNumber): array
    {
        $errors = [];

        if (count($row) !== count($headers)) {
            return [
                'rowNumber' => $rowNumber,
                'isValid'   => false,
                'errors'    => ['Column count mismatch. Expected ' . count($headers) . ', got ' . count($row)],
                'warnings'  => [],
                'data'      => $row,
                'preview'   => $this->getRawPreview($row, $headers),
            ];
        }

        $data = array_combine($headers, $row);
        $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

        if (empty($data['Advance block rejection ID'])) {
            $errors[] = 'Advance block rejection ID is required';
        }

        if (empty($data['Week Start Date'])) {
            $errors[] = 'Week Start Date is required';
        } else {
            try {
                Carbon::parse($data['Week Start Date']);
            } catch (\Exception $e) {
                $errors[] = 'Week Start Date is invalid: ' . $data['Week Start Date'];
            }
        }

        if (empty($data['Week End Date'])) {
            $errors[] = 'Week End Date is required';
        } else {
            try {
                Carbon::parse($data['Week End Date']);
            } catch (\Exception $e) {
                $errors[] = 'Week End Date is invalid: ' . $data['Week End Date'];
            }
        }

        if (!isset($data['Impacted blocks']) || $data['Impacted blocks'] === '') {
            $errors[] = 'Impacted blocks is required';
        } elseif (!is_numeric($data['Impacted blocks']) || (int)$data['Impacted blocks'] < 0) {
            $errors[] = 'Impacted blocks must be a non-negative integer';
        }

        if (!isset($data['Expected blocks']) || $data['Expected blocks'] === '') {
            $errors[] = 'Expected blocks is required';
        } elseif (!is_numeric($data['Expected blocks']) || (int)$data['Expected blocks'] < 0) {
            $errors[] = 'Expected blocks must be a non-negative integer';
        }

        return [
            'rowNumber' => $rowNumber,
            'isValid'   => empty($errors),
            'errors'    => $errors,
            'warnings'  => [],
            'data'      => $data,
            'preview'   => [
                ['key' => 'id',      'label' => 'Block Rejection ID', 'value' => substr((string)($data['Advance block rejection ID'] ?? ''), 0, 30)],
                ['key' => 'week',    'label' => 'Week',               'value' => (string)($data['Week'] ?? '')],
                ['key' => 'impacts', 'label' => 'Impacted Blocks',    'value' => (string)($data['Impacted blocks'] ?? '')],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────
    // BLOCKS
    // ─────────────────────────────────────────────────────────

    public function validateBlockCsv($file, ?int $tenantId = null): array
    {
        $this->results = [
            'valid'            => [],
            'invalid'          => [],
            'summary'          => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'headers'          => [],
            'expected_headers' => [],
        ];

        $filePath  = $file->getRealPath();
        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if (!$handle) throw new \Exception('Unable to open CSV file.');

        $isSuperAdmin = Auth::user()->tenant_id === null;

        if ($isSuperAdmin && !$tenantId) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'Tenant must be selected for super admin imports.'];
        }

        $expectedHeaders = [
            'Block ID',
            'Origin/ Destination node',
            'Block start time',
            'Block end time',
            'Block rejection time',
            'Block Rejection Bucket',
            'Block Rejection Reason',
            'Block Acceptance Status',
        ];

        $this->results['expected_headers'] = $expectedHeaders;

        $rawHeader = fgetcsv($handle, 0, $delimiter);
        if ($rawHeader === false) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV appears to be empty or unreadable.'];
        }

        $headerRow = $this->sanitizeHeaders($rawHeader);
        $this->results['headers'] = $headerRow;

        if (count($headerRow) !== count($expectedHeaders)) {
            fclose($handle);
            return [
                ...$this->results,
                'header_error' => 'Headers do not match. Expected ' . count($expectedHeaders) . ' columns, got ' . count($headerRow)
                    . '. Detected delimiter: "' . addcslashes($delimiter, "\t") . '".',
            ];
        }

        $normalizedIncoming = array_map(fn($h) => strtolower(trim($h)), $headerRow);
        $normalizedExpected  = array_map(fn($h) => strtolower(trim($h)), $expectedHeaders);

        if ($normalizedIncoming !== $normalizedExpected) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV header names/order do not match expected template.'];
        }

        $rowNumber = 1;
        while (($rawRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if (empty(array_filter($rawRow, fn($v) => trim((string)$v) !== ''))) continue;

            $this->results['summary']['total']++;
            $row    = $this->sanitizeRow($rawRow, count($expectedHeaders));
            $result = $this->validateBlockRow($row, $expectedHeaders, $rowNumber);

            if ($result['isValid']) {
                $this->results['valid'][]  = $result;
                $this->results['summary']['valid']++;
            } else {
                $this->results['invalid'][] = $result;
                $this->results['summary']['invalid']++;
            }
        }

        fclose($handle);
        return $this->results;
    }

    protected function validateBlockRow(array $row, array $headers, int $rowNumber): array
    {
        $errors = [];

        if (count($row) !== count($headers)) {
            return [
                'rowNumber' => $rowNumber,
                'isValid'   => false,
                'errors'    => ['Column count mismatch. Expected ' . count($headers) . ', got ' . count($row)],
                'warnings'  => [],
                'data'      => $row,
                'preview'   => $this->getRawPreview($row, $headers),
            ];
        }

        $data = array_combine($headers, $row);
        $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

        // ✅ Block bucket column is informational only — bucket is calculated
        // from timestamps during import, so we do NOT validate it here.

        if (empty($data['Block ID'])) $errors[] = 'Block ID is required';

        if (empty($data['Block start time'])) {
            $errors[] = 'Block start time is required';
        } else {
            try {
                Carbon::parse($data['Block start time']);
            } catch (\Exception $e) {
                $errors[] = 'Block start time is invalid: ' . $data['Block start time'];
            }
        }

        if (empty($data['Block end time'])) {
            $errors[] = 'Block end time is required';
        } else {
            try {
                Carbon::parse($data['Block end time']);
            } catch (\Exception $e) {
                $errors[] = 'Block end time is invalid: ' . $data['Block end time'];
            }
        }

        $hasReason = !empty($data['Block Rejection Reason']);
        if ($hasReason && empty($data['Block rejection time'])) {
            $errors[] = 'Block rejection time is required when a reason is provided';
        } elseif ($hasReason && !empty($data['Block rejection time'])) {
            try {
                Carbon::parse($data['Block rejection time']);
            } catch (\Exception $e) {
                $errors[] = 'Block rejection time is invalid: ' . $data['Block rejection time'];
            }
        }

        return [
            'rowNumber' => $rowNumber,
            'isValid'   => empty($errors),
            'skip'      => false,
            'errors'    => $errors,
            'warnings'  => [],
            'data'      => $data,
            'preview'   => [
                ['key' => 'block_id', 'label' => 'Block ID',   'value' => substr((string)($data['Block ID'] ?? ''), 0, 30)],
                ['key' => 'start',    'label' => 'Start Time', 'value' => (string)($data['Block start time'] ?? '')],
                ['key' => 'reason',   'label' => 'Reason',     'value' => (string)($data['Block Rejection Reason'] ?? '—')],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────
    // LOADS
    // ─────────────────────────────────────────────────────────

    public function validateLoadCsv($loadsFile, ?int $tenantId = null): array
    {
        $this->results = [
            'valid'            => [],
            'invalid'          => [],
            'summary'          => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'headers'          => [],
            'expected_headers' => [],
        ];

        $filePath  = $loadsFile->getRealPath();
        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if (!$handle) throw new \Exception('Unable to open loads CSV file.');

        $isSuperAdmin = Auth::user()->tenant_id === null;

        if ($isSuperAdmin && !$tenantId) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'Tenant must be selected for super admin imports.'];
        }

        $expectedHeaders = [
            'Trip ID',
            'Loads',
            'Load Status',
            'Driver Type',
            'Rejection Reason',
            'Run',
            'Distance',
            'Origin',
            'Origin Yard Arrival Time',
            'Origin Yard Departure Time',
            'Destination Yard Arrival Time',
            'Rejection Bucket',
        ];

        $this->results['expected_headers'] = $expectedHeaders;

        $rawHeader = fgetcsv($handle, 0, $delimiter);
        if ($rawHeader === false) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'Loads CSV appears to be empty or unreadable.'];
        }

        $headerRow = $this->sanitizeHeaders($rawHeader);
        $this->results['headers'] = $headerRow;

        if (count($headerRow) !== count($expectedHeaders)) {
            fclose($handle);
            return [
                ...$this->results,
                'header_error' => 'Headers do not match. Expected ' . count($expectedHeaders) . ' columns, got ' . count($headerRow)
                    . '. Detected delimiter: "' . addcslashes($delimiter, "\t") . '".',
            ];
        }

        $normalizedIncoming = array_map(fn($h) => strtolower(trim($h)), $headerRow);
        $normalizedExpected  = array_map(fn($h) => strtolower(trim($h)), $expectedHeaders);

        if ($normalizedIncoming !== $normalizedExpected) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV header names/order do not match expected template.'];
        }

        $rowNumber = 1;
        while (($rawRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            if (empty(array_filter($rawRow, fn($v) => trim((string)$v) !== ''))) continue;

            $this->results['summary']['total']++;
            $row    = $this->sanitizeRow($rawRow, count($expectedHeaders));
            $result = $this->validateLoadRow($row, $expectedHeaders, $rowNumber);

            if ($result['isValid']) {
                $this->results['valid'][]  = $result;
                $this->results['summary']['valid']++;
            } else {
                $this->results['invalid'][] = $result;
                $this->results['summary']['invalid']++;
            }
        }

        fclose($handle);
        return $this->results;
    }

    protected function validateLoadRow(array $row, array $headers, int $rowNumber): array
    {
        $errors = [];

        if (count($row) !== count($headers)) {
            return [
                'rowNumber' => $rowNumber,
                'isValid'   => false,
                'errors'    => ['Column count mismatch. Expected ' . count($headers) . ', got ' . count($row)],
                'warnings'  => [],
                'data'      => $row,
                'preview'   => $this->getRawPreview($row, $headers),
            ];
        }

        $data = array_combine($headers, $row);
        $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

        if (empty($data['Loads'])) $errors[] = 'Load ID (Loads column) is required';

        if (empty($data['Origin Yard Arrival Time'])) {
            $errors[] = 'Origin Yard Arrival Time is required';
        } else {
            try {
                Carbon::parse($data['Origin Yard Arrival Time']);
            } catch (\Exception $e) {
                $errors[] = 'Origin Yard Arrival Time is invalid: ' . $data['Origin Yard Arrival Time'];
            }
        }

        $hasReason = !empty($data['Rejection Reason']);

        // Bucket required only when there is a reason
        if ($hasReason && empty($data['Rejection Bucket'])) {
            $errors[] = 'Rejection Bucket is required when a Rejection Reason is provided';
        }

        // ✅ Validate bucket using the same normalizer as the importer
        // Accepts: "0-6 hours", "6+ hours", "After start time" (CSV values)
        //       + DB enum values for re-imports
        if (!empty($data['Rejection Bucket'])) {
            $resolved = $this->normalizeLoadBucket($data['Rejection Bucket']);
            if ($resolved === null) {
                $errors[] = 'Invalid Rejection Bucket: "' . $data['Rejection Bucket'] . '". '
                    . 'Expected one of: "0-6 hours", "6+ hours", "After start time"';
            }
        }

        return [
            'rowNumber' => $rowNumber,
            'isValid'   => empty($errors),
            'skip'      => false,
            'errors'    => $errors,
            'warnings'  => [],
            'data'      => $data,
            'preview'   => [
                ['key' => 'load_id', 'label' => 'Load ID', 'value' => substr((string)($data['Loads'] ?? ''), 0, 30)],
                ['key' => 'origin',  'label' => 'Origin',  'value' => (string)($data['Origin'] ?? '')],
                ['key' => 'reason',  'label' => 'Reason',  'value' => (string)($data['Rejection Reason'] ?? '—')],
            ],
        ];
    }
}
