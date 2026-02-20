<?php

namespace App\Services\Acceptance;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class RejectionImportValidationService
{
    protected array $results = [
        'valid'    => [],
        'invalid'  => [],
        'summary'  => ['total' => 0, 'valid' => 0, 'invalid' => 0],
        'headers'          => [],
        'expected_headers' => [],
    ];

    // ─────────────────────────────────────────────────────────
    // ADVANCED BLOCK
    // ─────────────────────────────────────────────────────────

    public function validateAdvancedBlockCsv($file, ?int $tenantId = null): array
    {
        $this->results = [
            'valid'    => [],
            'invalid'  => [],
            'summary'  => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'headers'          => [],
            'expected_headers' => [],
        ];

        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            throw new \Exception('Unable to open CSV file.');
        }

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

        $headerRow = fgetcsv($handle, 0, "\t");
        if ($headerRow === false) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV appears to be empty or unreadable.'];
        }

        $headerRow = array_map(fn($h) => trim((string) $h), $headerRow);
        $this->results['headers'] = $headerRow;

        if (count($headerRow) !== count($expectedHeaders)) {
            fclose($handle);
            return [
                ...$this->results,
                'header_error' => 'Headers do not match. Expected ' . count($expectedHeaders) . ' columns, got ' . count($headerRow),
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
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $rowNumber++;
            $this->results['summary']['total']++;

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

        // Week Start Date
        if (empty($data['Week Start Date'])) {
            $errors[] = 'Week Start Date is required';
        } else {
            try {
                Carbon::parse($data['Week Start Date']);
            } catch (\Exception $e) {
                $errors[] = 'Week Start Date is invalid: ' . $data['Week Start Date'];
            }
        }

        // Week End Date
        if (empty($data['Week End Date'])) {
            $errors[] = 'Week End Date is required';
        } else {
            try {
                Carbon::parse($data['Week End Date']);
            } catch (\Exception $e) {
                $errors[] = 'Week End Date is invalid: ' . $data['Week End Date'];
            }
        }

        // Impacted blocks
        if (!isset($data['Impacted blocks']) || $data['Impacted blocks'] === '') {
            $errors[] = 'Impacted blocks is required';
        } elseif (!is_numeric($data['Impacted blocks']) || (int)$data['Impacted blocks'] < 0) {
            $errors[] = 'Impacted blocks must be a non-negative integer';
        }

        // Expected blocks
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
            'valid'    => [],
            'invalid'  => [],
            'summary'  => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'headers'          => [],
            'expected_headers' => [],
        ];

        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            throw new \Exception('Unable to open CSV file.');
        }

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

        $headerRow = fgetcsv($handle, 0, "\t");
        if ($headerRow === false) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV appears to be empty or unreadable.'];
        }

        $headerRow = array_map(fn($h) => trim((string) $h), $headerRow);
        $this->results['headers'] = $headerRow;

        if (count($headerRow) !== count($expectedHeaders)) {
            fclose($handle);
            return [
                ...$this->results,
                'header_error' => 'Headers do not match. Expected ' . count($expectedHeaders) . ', got ' . count($headerRow),
            ];
        }

        $normalizedIncoming = array_map(fn($h) => strtolower(trim($h)), $headerRow);
        $normalizedExpected  = array_map(fn($h) => strtolower(trim($h)), $expectedHeaders);

        if ($normalizedIncoming !== $normalizedExpected) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV header names/order do not match expected template.'];
        }

        $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $rowNumber++;
            $this->results['summary']['total']++;

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

        // Only validate REJECTED rows — skip ACCEPTED rows silently
        $status = strtoupper(trim($data['Block Acceptance Status'] ?? ''));
        if ($status !== 'REJECTED') {
            // Mark as valid but flag to skip during import
            return [
                'rowNumber' => $rowNumber,
                'isValid'   => true,
                'skip'      => true,
                'errors'    => [],
                'warnings'  => ['Row skipped: Block was ACCEPTED, not REJECTED'],
                'data'      => $data,
                'preview'   => [],
            ];
        }

        if (empty($data['Block ID'])) {
            $errors[] = 'Block ID is required';
        }

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

        // rejection_datetime only required when there IS a reason
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
                ['key' => 'block_id',    'label' => 'Block ID',     'value' => substr((string)($data['Block ID'] ?? ''), 0, 30)],
                ['key' => 'start',       'label' => 'Start Time',   'value' => (string)($data['Block start time'] ?? '')],
                ['key' => 'reason',      'label' => 'Reason',       'value' => (string)($data['Block Rejection Reason'] ?? '—')],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────
    // LOADS
    // ─────────────────────────────────────────────────────────

    public function validateLoadCsv($loadsFile, ?int $tenantId = null): array
    {
        $this->results = [
            'valid'    => [],
            'invalid'  => [],
            'summary'  => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'headers'          => [],
            'expected_headers' => [],
        ];

        $handle = fopen($loadsFile->getRealPath(), 'r');
        if (!$handle) {
            throw new \Exception('Unable to open loads CSV file.');
        }

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

        $headerRow = fgetcsv($handle, 0, "\t");
        if ($headerRow === false) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'Loads CSV appears to be empty or unreadable.'];
        }

        $headerRow = array_map(fn($h) => trim((string) $h), $headerRow);
        $this->results['headers'] = $headerRow;

        if (count($headerRow) !== count($expectedHeaders)) {
            fclose($handle);
            return [
                ...$this->results,
                'header_error' => 'Headers do not match. Expected ' . count($expectedHeaders) . ', got ' . count($headerRow),
            ];
        }

        $normalizedIncoming = array_map(fn($h) => strtolower(trim($h)), $headerRow);
        $normalizedExpected  = array_map(fn($h) => strtolower(trim($h)), $expectedHeaders);

        if ($normalizedIncoming !== $normalizedExpected) {
            fclose($handle);
            return [...$this->results, 'header_error' => 'CSV header names/order do not match expected template.'];
        }

        $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $rowNumber++;
            $this->results['summary']['total']++;

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

        // Only import REJECTED loads
        $status = strtoupper(trim($data['Load Status'] ?? ''));
        if ($status !== 'REJECTED') {
            return [
                'rowNumber' => $rowNumber,
                'isValid'   => true,
                'skip'      => true,
                'errors'    => [],
                'warnings'  => ['Row skipped: Load status is not REJECTED'],
                'data'      => $data,
                'preview'   => [],
            ];
        }

        if (empty($data['Loads'])) {
            $errors[] = 'Load ID (Loads column) is required';
        }

        if (empty($data['Origin Yard Arrival Time'])) {
            $errors[] = 'Origin Yard Arrival Time is required';
        } else {
            try {
                Carbon::parse($data['Origin Yard Arrival Time']);
            } catch (\Exception $e) {
                $errors[] = 'Origin Yard Arrival Time is invalid: ' . $data['Origin Yard Arrival Time'];
            }
        }

        // Rejection bucket only required if there IS a reason
        $hasReason = !empty($data['Rejection Reason']);
        if ($hasReason && empty($data['Rejection Bucket'])) {
            $errors[] = 'Rejection Bucket is required when a Rejection Reason is provided';
        }

        if (!empty($data['Rejection Bucket'])) {
            $validBuckets = [
                'rejected_after_start_time',
                'rejected_0_6_hours_before_start_time',
                'rejected_6_plus_hours_before_start_time',
                // also accept human-readable variants from the raw data
                'less than 24 hours',
                '24+ hours',
                'after start time',
            ];
            if (!in_array(strtolower($data['Rejection Bucket']), array_map('strtolower', $validBuckets), true)) {
                $errors[] = 'Invalid Rejection Bucket: ' . $data['Rejection Bucket'];
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
                ['key' => 'load_id', 'label' => 'Load ID',     'value' => substr((string)($data['Loads'] ?? ''), 0, 30)],
                ['key' => 'origin',  'label' => 'Origin',      'value' => (string)($data['Origin'] ?? '')],
                ['key' => 'reason',  'label' => 'Reason',      'value' => (string)($data['Rejection Reason'] ?? '—')],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────
    // SHARED HELPERS
    // ─────────────────────────────────────────────────────────

    private function getRawPreview(array $row, array $headers): array
    {
        $out = [];
        foreach (array_slice($headers, 0, 3) as $i => $h) {
            $val = isset($row[$i]) ? (string) $row[$i] : '';
            if (strlen($val) > 30) {
                $val = substr($val, 0, 30) . '...';
            }
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

    public function generateErrorReport(array $invalidRows): string
    {
        $fileName = 'rejections_import_errors_' . date('Y-m-d_His') . '.csv';
        $dir  = 'temp-imports';
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
                    if ($label !== '' && $val !== '') {
                        $parts[] = "{$label}: {$val}";
                    }
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
}
