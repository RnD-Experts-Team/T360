<?php

namespace App\Services\Acceptance;

use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Rejection;
use App\Models\RejectedBlock;
use App\Models\RejectedLoad;
use App\Models\AdvancedRejectedBlock;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RejectionImportExportService
{
    // ─────────────────────────────────────────────────────────
    // SHARED CSV HELPERS
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

    /**
     * Strip BOM, trim all elements, remove trailing empty columns.
     */
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

    /**
     * Trim all values and drop trailing empty cells beyond expected count.
     */
    private function sanitizeRow(array $row, int $expectedCount): array
    {
        $row = array_map(fn($v) => is_string($v) ? trim($v) : $v, $row);
        while (count($row) > $expectedCount && end($row) === '') {
            array_pop($row);
        }
        return $row;
    }

    /**
     * Returns true if every cell in the row is empty (blank line guard).
     */
    private function isBlankRow(array $row): bool
    {
        return empty(array_filter($row, fn($v) => trim((string)$v) !== ''));
    }

    // ═══════════════════════════════════════════════════════════
    // IMPORT — ADVANCED BLOCKS
    // ═══════════════════════════════════════════════════════════

    public function importAdvancedBlocks($request, ?int $tenantId = null): array
    {
        $isSuperAdmin     = Auth::user()->tenant_id === null;
        $resolvedTenantId = $isSuperAdmin ? $tenantId : Auth::user()->tenant_id;

        $file      = $request->file('csv_file');
        $filePath  = $file->getRealPath();
        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if (!$handle) throw new \Exception('Could not open the CSV file.');

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

        // Consume and sanitize header row
        $rawHeader = fgetcsv($handle, 0, $delimiter);
        // (header already validated — just skip it cleanly)

        $imported = 0;
        $skipped  = 0;

        while (($rawRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankRow($rawRow)) continue;

            $row = $this->sanitizeRow($rawRow, count($expectedHeaders));

            if (count($row) < count($expectedHeaders)) {
                $skipped++;
                continue;
            }

            $data = array_combine($expectedHeaders, $row);
            $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

            try {
                $weekStart = Carbon::parse($data['Week Start Date']);
                $weekEnd   = Carbon::parse($data['Week End Date']);
            } catch (\Exception $e) {
                $skipped++;
                continue;
            }

            $blockRejectionId = $data['Advance block rejection ID'];
            $impactedBlocks   = (int) $data['Impacted blocks'];
            $expectedBlocks   = (int) $data['Expected blocks'];
            $reason           = $data['Reason(s)'] ?? null;
            $hasReason        = !empty($reason);

            $penalty = $hasReason ? round(0.85 * $impactedBlocks, 2) : 0;

            $existingRejectedBlock = AdvancedRejectedBlock::where('advance_block_rejection_id', $blockRejectionId)->first();

            if ($existingRejectedBlock) {
                $existingRejection = $existingRejectedBlock->rejection;
                $hadReason         = !empty($existingRejection->rejection_reason);

                if ($hadReason && !$hasReason) {
                    $existingRejection->update([
                        'carrier_controllable' => false,
                        'disputed'             => 'won',
                    ]);
                    $imported++;
                    continue;
                }

                $skipped++;
                continue;
            }

            $rejection = Rejection::create([
                'tenant_id'            => $resolvedTenantId,
                'date'                 => $weekStart->toDateString(),
                'penalty'              => $penalty,
                'disputed'             => 'none',
                'carrier_controllable' => $hasReason,
                'driver_controllable'  => $hasReason,
                'rejection_reason'     => $hasReason ? $reason : null,
            ]);

            AdvancedRejectedBlock::create([
                'rejection_id'               => $rejection->id,
                'advance_block_rejection_id' => $blockRejectionId,
                'week_start'                 => $weekStart,
                'week_end'                   => $weekEnd,
                'impacted_blocks'            => $impactedBlocks,
                'expected_blocks'            => $expectedBlocks,
            ]);

            $imported++;
        }

        fclose($handle);
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    // ═══════════════════════════════════════════════════════════
    // IMPORT — BLOCKS
    // ═══════════════════════════════════════════════════════════

    public function importBlocks($request, ?int $tenantId = null): array
    {
        $isSuperAdmin     = Auth::user()->tenant_id === null;
        $resolvedTenantId = $isSuperAdmin ? $tenantId : Auth::user()->tenant_id;

        $file      = $request->file('csv_file');
        $filePath  = $file->getRealPath();
        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if (!$handle) throw new \Exception('Could not open the CSV file.');

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

        // Consume header
        fgetcsv($handle, 0, $delimiter);

        $imported = 0;
        $skipped  = 0;

        while (($rawRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankRow($rawRow)) continue;

            $row = $this->sanitizeRow($rawRow, count($expectedHeaders));

            if (count($row) < count($expectedHeaders)) {
                $skipped++;
                continue;
            }

            $data = array_combine($expectedHeaders, $row);
            $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

            // ✅ Import ALL rows regardless of acceptance status
            $blockId   = $data['Block ID'];
            $hasReason = !empty($data['Block Rejection Reason']);

            try {
                $blockStart = Carbon::parse($data['Block start time']);
                $blockEnd   = Carbon::parse($data['Block end time']);
            } catch (\Exception $e) {
                $skipped++;
                continue;
            }

            $rejectionDatetime = null;
            $rejectionBucket   = null;

            if ($hasReason) {
                try {
                    $rejectionDatetime = Carbon::parse($data['Block rejection time']);
                } catch (\Exception $e) {
                    $skipped++;
                    continue;
                }

                $hoursBeforeStart = $rejectionDatetime->diffInHours($blockStart, false);
                $rejectionBucket  = $hoursBeforeStart < 24 ? 'less_than_24' : 'more_than_24';
                $penalty          = $rejectionBucket === 'less_than_24' ? 4 : 1;
            } else {
                $penalty = 0;
            }

            $existingBlock = RejectedBlock::where('block_id', $blockId)->first();

            if ($existingBlock) {
                $existingRejection = $existingBlock->rejection;
                $hadReason         = !empty($existingRejection->rejection_reason);

                if ($hadReason && !$hasReason) {
                    $existingRejection->update([
                        'carrier_controllable' => false,
                        'disputed'             => 'won',
                    ]);
                    $imported++;
                    continue;
                }

                $skipped++;
                continue;
            }

            $rejection = Rejection::create([
                'tenant_id'            => $resolvedTenantId,
                'date'                 => $blockStart->toDateString(),
                'penalty'              => $penalty,
                'disputed'             => 'none',
                'carrier_controllable' => $hasReason,
                'driver_controllable'  => $hasReason,
                'rejection_reason'     => $hasReason ? $data['Block Rejection Reason'] : null,
            ]);

            RejectedBlock::create([
                'rejection_id'       => $rejection->id,
                'block_id'           => $blockId,
                'driver_name'        => null,
                'block_start'        => $blockStart,
                'block_end'          => $blockEnd,
                'rejection_datetime' => $rejectionDatetime,
                'rejection_bucket'   => $rejectionBucket,
            ]);

            $imported++;
        }

        fclose($handle);
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    // ═══════════════════════════════════════════════════════════
    // IMPORT — LOADS
    // ═══════════════════════════════════════════════════════════

    public function importLoads($request, ?int $tenantId = null): array
    {
        $isSuperAdmin     = Auth::user()->tenant_id === null;
        $resolvedTenantId = $isSuperAdmin ? $tenantId : Auth::user()->tenant_id;

        $loadsFile = $request->file('csv_file');
        $tripsFile = $request->file('trips_file');

        $driverLookup = $tripsFile ? $this->buildDriverLookup($tripsFile) : [];

        $filePath  = $loadsFile->getRealPath();
        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if (!$handle) throw new \Exception('Could not open loads CSV file.');

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

        // Consume header
        fgetcsv($handle, 0, $delimiter);

        $imported = 0;
        $skipped  = 0;

        while (($rawRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankRow($rawRow)) continue;

            $row = $this->sanitizeRow($rawRow, count($expectedHeaders));

            if (count($row) < count($expectedHeaders)) {
                $skipped++;
                continue;
            }

            $data = array_combine($expectedHeaders, $row);
            $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

            // ✅ Import ALL rows regardless of load status
            $loadId    = $data['Loads'];
            $hasReason = !empty($data['Rejection Reason']);

            try {
                $originYardArrival = Carbon::parse($data['Origin Yard Arrival Time']);
            } catch (\Exception $e) {
                $skipped++;
                continue;
            }

            $rejectionBucket = null;
            $penalty         = 0;

            if ($hasReason) {
                $rawBucket       = strtolower(trim($data['Rejection Bucket'] ?? ''));
                $rejectionBucket = $this->normalizeLoadRejectionBucket($rawBucket);

                if (!$rejectionBucket) {
                    $skipped++;
                    continue;
                }

                $penalty = match ($rejectionBucket) {
                    'rejected_after_start_time'               => 8,
                    'rejected_0_6_hours_before_start_time'    => 4,
                    'rejected_6_plus_hours_before_start_time' => 1,
                    default                                   => 0,
                };
            }

            $driverName = $driverLookup[$loadId] ?? null;

            $existingLoad = RejectedLoad::where('load_id', $loadId)->first();

            if ($existingLoad) {
                $existingRejection = $existingLoad->rejection;
                $hadReason         = !empty($existingRejection->rejection_reason);

                if ($hadReason && !$hasReason) {
                    $existingRejection->update([
                        'carrier_controllable' => false,
                        'disputed'             => 'won',
                    ]);
                    $imported++;
                    continue;
                }

                if ($driverName && !$existingLoad->driver_name) {
                    $existingLoad->update(['driver_name' => $driverName]);
                }

                $skipped++;
                continue;
            }

            $rejection = Rejection::create([
                'tenant_id'            => $resolvedTenantId,
                'date'                 => $originYardArrival->toDateString(),
                'penalty'              => $penalty,
                'disputed'             => 'none',
                'carrier_controllable' => $hasReason,
                'driver_controllable'  => $hasReason,
                'rejection_reason'     => $hasReason ? $data['Rejection Reason'] : null,
            ]);

            RejectedLoad::create([
                'rejection_id'        => $rejection->id,
                'load_id'             => $loadId,
                'driver_name'         => $driverName,
                'origin_yard_arrival' => $originYardArrival,
                'rejection_bucket'    => $rejectionBucket,
            ]);

            $imported++;
        }

        fclose($handle);
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    // ═══════════════════════════════════════════════════════════
    // IMPORT — TRIPS ONLY
    // ═══════════════════════════════════════════════════════════

    public function importTripsOnly($request, ?int $tenantId = null): array
    {
        $isSuperAdmin     = Auth::user()->tenant_id === null;
        $resolvedTenantId = $isSuperAdmin ? $tenantId : Auth::user()->tenant_id;

        $driverLookup = $this->buildDriverLookup($request->file('trips_file'));
        $updated      = $this->backfillDriverNamesFromLookup($driverLookup, $resolvedTenantId);
        return ['updated' => $updated];
    }

    // ─────────────────────────────────────────────────────────
    // DRIVER LOOKUP BUILDER
    // ─────────────────────────────────────────────────────────

    private function buildDriverLookup($tripsFile): array
    {
        $filePath  = $tripsFile->getRealPath();
        $delimiter = $this->detectDelimiter($filePath);

        $handle = fopen($filePath, 'r');
        if (!$handle) return [];

        $rawHeader = fgetcsv($handle, 0, $delimiter);
        if ($rawHeader === false) {
            fclose($handle);
            return [];
        }

        // Sanitize header
        $headerRow = $this->sanitizeHeaders($rawHeader);
        $idx       = array_flip($headerRow);

        $loadIdIdx     = $idx['Load ID']    ?? null;
        $driverNameIdx = $idx['Driver Name'] ?? null;
        $operatorIdIdx = $idx['Operator ID'] ?? null;

        // Load ID + Driver Name are required
        if ($loadIdIdx === null || $driverNameIdx === null) {
            fclose($handle);
            return [];
        }

        $allRows = [];
        while (($rawRow = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isBlankRow($rawRow)) continue;
            $allRows[] = $this->sanitizeRow($rawRow, count($headerRow));
        }
        fclose($handle);

        $lookup = [];

        foreach ($allRows as $i => $row) {
            $loadId = isset($row[$loadIdIdx]) ? trim($row[$loadIdIdx]) : '';
            if (empty($loadId)) continue;

            $driverName = isset($row[$driverNameIdx]) ? trim($row[$driverNameIdx]) : '';

            // ✅ If driver name exists, use it directly
            if (!empty($driverName)) {
                $lookup[$loadId] = $driverName;
                continue;
            }

            // ✅ Fallback: look backward for same Operator ID with ANY driver name
            if ($operatorIdIdx === null) continue;

            $operatorId = isset($row[$operatorIdIdx]) ? trim($row[$operatorIdIdx]) : '';
            if (empty($operatorId)) continue;

            $resolvedDriver = null;

            for ($j = $i - 1; $j >= 0; $j--) {
                $prevRow      = $allRows[$j];
                $prevOperator = isset($prevRow[$operatorIdIdx]) ? trim($prevRow[$operatorIdIdx]) : '';
                $prevDriver   = isset($prevRow[$driverNameIdx]) ? trim($prevRow[$driverNameIdx]) : '';

                if ($prevOperator === $operatorId && !empty($prevDriver)) {
                    $resolvedDriver = $prevDriver;
                    break;
                }
            }

            if ($resolvedDriver) {
                $lookup[$loadId] = $resolvedDriver;
            }
        }

        return $lookup;
    }

    private function backfillDriverNamesFromLookup(array $driverLookup, int $tenantId): int
    {
        $updated = 0;

        foreach ($driverLookup as $loadId => $driverName) {
            $load = RejectedLoad::where('load_id', $loadId)
                ->whereHas('rejection', fn($q) => $q->where('tenant_id', $tenantId))
                ->first();

            if ($load) {
                $load->update(['driver_name' => $driverName]);
                $updated++;
            }
        }

        return $updated;
    }

    // ─────────────────────────────────────────────────────────
    // BUCKET NORMALIZER
    // ─────────────────────────────────────────────────────────

    private function normalizeLoadRejectionBucket(string $raw): ?string
    {
        $normalized = strtolower(trim($raw));

        // ── Exact DB enum passthrough (re-import safe) ──────────
        if ($normalized === 'rejected_after_start_time')               return 'rejected_after_start_time';
        if ($normalized === 'rejected_0_6_hours_before_start_time')    return 'rejected_0_6_hours_before_start_time';
        if ($normalized === 'rejected_6_plus_hours_before_start_time') return 'rejected_6_plus_hours_before_start_time';

        // ── "After start time" ───────────────────────────────────
        if (str_contains($normalized, 'after start')) return 'rejected_after_start_time';

        // ── "0-6 hours" — must check before 6+ to avoid false match ──
        // Matches: "0-6 hours", "0 - 6 hours", "0–6 hours"
        if (preg_match('/0\s*[-–]\s*6/', $normalized)) return 'rejected_0_6_hours_before_start_time';

        // ── "6+ hours" ───────────────────────────────────────────
        // Matches: "6+ hours", "6 + hours", "6 plus hours", "6plus"
        if (preg_match('/6\s*\+/', $normalized) || str_contains($normalized, '6 plus') || str_contains($normalized, '6plus')) {
            return 'rejected_6_plus_hours_before_start_time';
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════
    // EXPORT
    // ═══════════════════════════════════════════════════════════

    public function exportRejections()
    {
        $isSuperAdmin = Auth::user()->tenant_id === null;

        $query = Rejection::with([
            'tenant',
            'advancedRejectedBlock',
            'rejectedBlock',
            'rejectedLoad',
        ]);

        if (!$isSuperAdmin) {
            $query->where('tenant_id', Auth::user()->tenant_id);
        }

        $rejections = $query->get();

        if ($rejections->isEmpty()) {
            return redirect()->back()->with('error', 'No rejection records to export.');
        }

        $fileName = 'rejections_' . Str::random(8) . '.csv';
        $filePath = public_path($fileName);

        // ✅ Write UTF-8 BOM so Excel renders special characters correctly
        $file = fopen($filePath, 'w');
        fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = [];
        if ($isSuperAdmin) $headers[] = 'Company Name';

        $headers = array_merge($headers, [
            'Date',
            'Rejection Type',
            'Penalty',
            'Disputed',
            'Carrier Controllable',
            'Driver Controllable',
            'Rejection Reason',
            // Advanced block
            'Advance Block Rejection ID',
            'Week',
            'Week Start',
            'Week End',
            'Expected Blocks',
            'Impacted Blocks',
            // Block
            'Block ID',
            'Driver Name',
            'Block Start',
            'Block End',
            'Rejection Date/Time',
            'Rejection Bucket',
            // Load
            'Load ID',
            'Origin Yard Arrival',
            'Load Rejection Bucket',
        ]);

        fputcsv($file, $headers);

        foreach ($rejections as $rejection) {
            $exportPenalty = $rejection->disputed === 'won' ? 0 : $rejection->penalty;

            $baseRow = [];
            if ($isSuperAdmin) $baseRow[] = $rejection->tenant->name ?? '';

            $baseRow = array_merge($baseRow, [
                Carbon::parse($rejection->date)->format('m/d/Y'),
                $this->humanRejectionType($rejection),
                $exportPenalty,
                $this->humanDisputed($rejection->disputed),
                $rejection->carrier_controllable ? 'Yes' : 'No',
                $rejection->driver_controllable  ? 'Yes' : 'No',
                $rejection->rejection_reason ?? '',   // ✅ empty string instead of em-dash
            ]);

            $advBlock = $rejection->advancedRejectedBlock->first();
            $block    = $rejection->rejectedBlock->first();
            $load     = $rejection->rejectedLoad->first();

            if ($advBlock) {
                fputcsv($file, array_merge($baseRow, [
                    $advBlock->advance_block_rejection_id ?? '',
                    Carbon::parse($advBlock->week_start)->format('W'),
                    Carbon::parse($advBlock->week_start)->format('m/d/Y'),
                    Carbon::parse($advBlock->week_end)->format('m/d/Y'),
                    $advBlock->expected_blocks,
                    $advBlock->impacted_blocks,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ]));
            } elseif ($block) {
                fputcsv($file, array_merge($baseRow, [
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $block->block_id           ?? '',
                    $block->driver_name        ?? '',    // ✅ empty string, not em-dash
                    $block->block_start        ? Carbon::parse($block->block_start)->format('m/d/Y H:i')        : '',
                    $block->block_end          ? Carbon::parse($block->block_end)->format('m/d/Y H:i')          : '',
                    $block->rejection_datetime ? Carbon::parse($block->rejection_datetime)->format('m/d/Y H:i') : '',
                    $block->rejection_bucket   ? $this->humanBlockBucket($block->rejection_bucket)              : '',
                    '',
                    '',
                    '',
                ]));
            } elseif ($load) {
                fputcsv($file, array_merge($baseRow, [
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $load->load_id             ?? '',
                    $load->origin_yard_arrival ? Carbon::parse($load->origin_yard_arrival)->format('m/d/Y H:i') : '',
                    $load->rejection_bucket    ? $this->humanLoadBucket($load->rejection_bucket)                : '',
                ]));
            } else {
                fputcsv($file, array_merge($baseRow, array_fill(0, 15, '')));
            }
        }

        fclose($file);
        return Response::download($filePath)->deleteFileAfterSend(true);
    }

    private function humanRejectionType(Rejection $rejection): string
    {
        $hasReason = !empty($rejection->rejection_reason);

        if ($rejection->advancedRejectedBlock->isNotEmpty()) {
            // Advanced blocks always show as "Advanced Rejection" regardless of reason
            return 'Advanced Rejection';
        }

        if ($rejection->rejectedBlock->isNotEmpty()) {
            // ✅ "Rejected Block" only if there's a reason, otherwise just "Block"
            return $hasReason ? 'Rejected Block' : 'Block';
        }

        if ($rejection->rejectedLoad->isNotEmpty()) {
            // ✅ "Rejected Load" only if there's a reason, otherwise just "Load"
            return $hasReason ? 'Rejected Load' : 'Load';
        }

        return '';
    }

    private function humanBlockBucket(?string $bucket): string
    {
        return match ($bucket) {
            'more_than_24' => '24+ hours before start',
            'less_than_24' => 'Less than 24 hours before start',
            default        => $bucket ?? '',
        };
    }

    private function humanLoadBucket(?string $bucket): string
    {
        return match ($bucket) {
            'rejected_after_start_time'               => 'After start time',
            'rejected_0_6_hours_before_start_time'    => '0-6 hours before start',
            'rejected_6_plus_hours_before_start_time' => '6+ hours before start',
            default                                   => $bucket ?? '',
        };
    }

    private function humanDisputed(?string $disputed): string
    {
        return match ($disputed) {
            'none'    => 'None',
            'pending' => 'Pending',
            'won'     => 'Won',
            'lost'    => 'Lost',
            default   => '',
        };
    }
}
