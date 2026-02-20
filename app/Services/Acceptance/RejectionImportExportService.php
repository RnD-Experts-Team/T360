<?php

namespace App\Services\Acceptance;

use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\Tenant;
use App\Models\Rejection;
use App\Models\RejectedBlock;
use App\Models\RejectedLoad;
use App\Models\AdvancedRejectedBlock;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;

class RejectionImportExportService
{
    // ═══════════════════════════════════════════════════════════
    // IMPORT — ADVANCED BLOCKS
    // ═══════════════════════════════════════════════════════════

    public function importAdvancedBlocks($request, ?int $tenantId = null): array
    {
        $isSuperAdmin = Auth::user()->tenant_id === null;
        $resolvedTenantId = $isSuperAdmin ? $tenantId : Auth::user()->tenant_id;

        $file   = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            throw new \Exception('Could not open the CSV file.');
        }

        // Skip header
        fgetcsv($handle, 0, "\t");

        $imported = 0;
        $skipped  = 0;

        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($row) < 11) {
                $skipped++;
                continue;
            }

            $headers = [
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

            $data = array_combine($headers, array_slice($row, 0, 11));
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

            // Calculate penalty
            $penalty = $hasReason ? round(0.85 * $impactedBlocks, 2) : 0;

            // Check if this block rejection ID already exists
            $existingRejectedBlock = AdvancedRejectedBlock::where('advance_block_rejection_id', $blockRejectionId)
                ->first();

            if ($existingRejectedBlock) {
                $existingRejection = $existingRejectedBlock->rejection;
                $hadReason         = !empty($existingRejection->rejection_reason);

                // Previously had reason, now doesn't → mark as Won
                if ($hadReason && !$hasReason) {
                    $existingRejection->update([
                        'carrier_controllable' => false,
                        // driver_controllable stays as is
                        'disputed'             => 'won',
                    ]);
                    $imported++;
                    continue;
                }

                // Otherwise skip (no meaningful change)
                $skipped++;
                continue;
            }

            // Create new rejection
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

        $file   = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            throw new \Exception('Could not open the CSV file.');
        }

        // Skip header
        fgetcsv($handle, 0, "\t");

        $imported = 0;
        $skipped  = 0;

        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($row) < 8) {
                $skipped++;
                continue;
            }

            $headers = [
                'Block ID',
                'Origin/ Destination node',
                'Block start time',
                'Block end time',
                'Block rejection time',
                'Block Rejection Bucket',
                'Block Rejection Reason',
                'Block Acceptance Status',
            ];

            $data = array_combine($headers, array_slice($row, 0, 8));
            $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

            // Only import REJECTED rows
            if (strtoupper($data['Block Acceptance Status']) !== 'REJECTED') {
                $skipped++;
                continue;
            }

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

            // Check for existing block by Block ID
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

            // Create rejection
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
                'rejection_id'        => $rejection->id,
                'block_id'            => $blockId,
                'driver_name'         => null,
                'block_start'         => $blockStart,
                'block_end'           => $blockEnd,
                'rejection_datetime'  => $rejectionDatetime,
                'rejection_bucket'    => $rejectionBucket,
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
        $tripsFile = $request->file('trips_file'); // optional

        // Build driver lookup from trips CSV if provided
        $driverLookup = $tripsFile ? $this->buildDriverLookup($tripsFile) : [];

        $handle = fopen($loadsFile->getRealPath(), 'r');
        if (!$handle) {
            throw new \Exception('Could not open loads CSV file.');
        }

        // Skip header
        fgetcsv($handle, 0, "\t");

        $imported = 0;
        $skipped  = 0;

        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($row) < 12) {
                $skipped++;
                continue;
            }

            $headers = [
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

            $data = array_combine($headers, array_slice($row, 0, 12));
            $data = collect($data)->map(fn($v) => is_string($v) ? trim($v) : $v)->toArray();

            // Only import REJECTED loads
            if (strtoupper($data['Load Status']) !== 'REJECTED') {
                $skipped++;
                continue;
            }

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
                $rawBucket = strtolower(trim($data['Rejection Bucket'] ?? ''));
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

            // Resolve driver name from lookup
            $driverName = $driverLookup[$loadId] ?? null;

            // Check for existing load by Load ID
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

                // Update driver name if we now have one
                if ($driverName && !$existingLoad->driver_name) {
                    $existingLoad->update(['driver_name' => $driverName]);
                }

                $skipped++;
                continue;
            }

            // Create rejection
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
                'rejection_id'       => $rejection->id,
                'load_id'            => $loadId,
                'driver_name'        => $driverName,
                'origin_yard_arrival' => $originYardArrival,
                'rejection_bucket'   => $rejectionBucket,
            ]);

            $imported++;
        }

        fclose($handle);

        // If only a trips file was uploaded (no loads file), resolve missing driver names
        if ($tripsFile && !$loadsFile) {
            $this->backfillDriverNamesFromLookup($driverLookup, $resolvedTenantId);
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Upload trips only — backfill driver names for existing rejected loads.
     */
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

    /**
     * Build a [load_id => driver_name] map from the trips CSV.
     *
     * For each Load ID row:
     *   1. Use Driver Name if non-empty.
     *   2. Otherwise find the nearest preceding row with the same Operator ID
     *      that has a non-empty Estimated Cost, and use that row's Driver Name.
     */
    private function buildDriverLookup($tripsFile): array
    {
        $handle = fopen($tripsFile->getRealPath(), 'r');
        if (!$handle) {
            return [];
        }

        $headerRow = fgetcsv($handle, 0, "\t");
        if ($headerRow === false) {
            fclose($handle);
            return [];
        }

        $headerRow = array_map(fn($h) => trim((string) $h), $headerRow);

        // Map column names to indices
        $idx = array_flip($headerRow);

        $loadIdIdx      = $idx['Load ID']          ?? null;
        $driverNameIdx  = $idx['Driver Name']       ?? null;
        $operatorIdIdx  = $idx['Operator ID']       ?? null;
        $estCostIdx     = $idx['Estimated Cost']    ?? null;

        if ($loadIdIdx === null || $driverNameIdx === null) {
            fclose($handle);
            return [];
        }

        // Read all rows into memory for the look-back logic
        $allRows  = [];
        $rowNum   = 0;
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            $allRows[] = ['rowNum' => $rowNum, 'data' => $row];
            $rowNum++;
        }
        fclose($handle);

        $lookup = [];

        foreach ($allRows as $i => $entry) {
            $row    = $entry['data'];
            $loadId = isset($row[$loadIdIdx]) ? trim($row[$loadIdIdx]) : '';

            if (empty($loadId)) {
                continue;
            }

            $driverName = isset($row[$driverNameIdx]) ? trim($row[$driverNameIdx]) : '';

            if (!empty($driverName)) {
                $lookup[$loadId] = $driverName;
                continue;
            }

            // Driver name empty — use Operator ID look-back
            if ($operatorIdIdx === null || $estCostIdx === null) {
                continue;
            }

            $operatorId = isset($row[$operatorIdIdx]) ? trim($row[$operatorIdIdx]) : '';

            if (empty($operatorId)) {
                continue;
            }

            // Search backwards from current row for matching operator with non-empty estimated cost
            $resolvedDriver = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                $prevRow       = $allRows[$j]['data'];
                $prevOperator  = isset($prevRow[$operatorIdIdx]) ? trim($prevRow[$operatorIdIdx]) : '';
                $prevCost      = isset($prevRow[$estCostIdx])    ? trim($prevRow[$estCostIdx])    : '';
                $prevDriver    = isset($prevRow[$driverNameIdx]) ? trim($prevRow[$driverNameIdx]) : '';

                if ($prevOperator === $operatorId && !empty($prevCost) && !empty($prevDriver)) {
                    $resolvedDriver = $prevDriver;
                    break; // first (nearest) match
                }
            }

            if ($resolvedDriver) {
                $lookup[$loadId] = $resolvedDriver;
            }
        }

        return $lookup;
    }

    /**
     * Backfill driver names on existing RejectedLoad records using a lookup map.
     */
    private function backfillDriverNamesFromLookup(array $driverLookup, int $tenantId): int
    {
        $updated = 0;

        foreach ($driverLookup as $loadId => $driverName) {
            $load = RejectedLoad::where('load_id', $loadId)
                ->whereHas('rejection', fn($q) => $q->where('tenant_id', $tenantId))
                ->whereNull('driver_name')
                ->first();

            if ($load) {
                $load->update(['driver_name' => $driverName]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Normalize raw rejection bucket strings from the CSV to DB enum values.
     */
    private function normalizeLoadRejectionBucket(string $raw): ?string
    {
        return match (true) {
            str_contains($raw, 'after start')              => 'rejected_after_start_time',
            str_contains($raw, '0') && str_contains($raw, '6') => 'rejected_0_6_hours_before_start_time',
            str_contains($raw, '6+') || str_contains($raw, '6 plus') || str_contains($raw, 'less than 24') || str_contains($raw, '24+') => 'rejected_6_plus_hours_before_start_time',
            $raw === 'rejected_after_start_time'           => 'rejected_after_start_time',
            $raw === 'rejected_0_6_hours_before_start_time' => 'rejected_0_6_hours_before_start_time',
            $raw === 'rejected_6_plus_hours_before_start_time' => 'rejected_6_plus_hours_before_start_time',
            default => null,
        };
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
        $file     = fopen($filePath, 'w');

        // Build headers
        $headers = [];
        if ($isSuperAdmin) {
            $headers[] = 'Company Name';
        }
        $headers = array_merge($headers, [
            'Date',
            'Rejection Type',
            'Penalty',
            'Disputed',
            'Carrier Controllable',
            'Driver Controllable',
            'Rejection Reason',
            // Advanced block fields
            'Advance Block Rejection ID',
            'Week',
            'Week Start',
            'Week End',
            'Expected Blocks',
            'Impacted Blocks',
            // Block fields
            'Block ID',
            'Driver Name',
            'Block Start',
            'Block End',
            'Rejection Date/Time',
            'Rejection Bucket',
            // Load fields
            'Load ID',
            'Origin Yard Arrival',
            'Load Rejection Bucket',
        ]);

        fputcsv($file, $headers);

        foreach ($rejections as $rejection) {
            // Penalty is 0 on export when won
            $exportPenalty = $rejection->disputed === 'won' ? 0 : $rejection->penalty;

            $baseRow = [];

            if ($isSuperAdmin) {
                $baseRow[] = $rejection->tenant->name ?? '—';
            }

            $baseRow = array_merge($baseRow, [
                Carbon::parse($rejection->date)->format('m/d/Y'),
                $this->humanRejectionType($rejection),
                $exportPenalty,
                $this->humanDisputed($rejection->disputed),
                $rejection->carrier_controllable ? 'Yes' : 'No',
                $rejection->driver_controllable  ? 'Yes' : 'No',
                $rejection->rejection_reason ?? '—',
            ]);

            // Sub-record data
            $advBlock = $rejection->advancedRejectedBlock->first();
            $block    = $rejection->rejectedBlock->first();
            $load     = $rejection->rejectedLoad->first();

            if ($advBlock) {
                fputcsv($file, array_merge($baseRow, [
                    $advBlock->advance_block_rejection_id ?? '—',
                    Carbon::parse($advBlock->week_start)->format('W'),
                    Carbon::parse($advBlock->week_start)->format('m/d/Y'),
                    Carbon::parse($advBlock->week_end)->format('m/d/Y'),
                    $advBlock->expected_blocks,
                    $advBlock->impacted_blocks,
                    // Block fields — empty
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    // Load fields — empty
                    '',
                    '',
                    '',
                ]));
            } elseif ($block) {
                fputcsv($file, array_merge($baseRow, [
                    // Advanced block fields — empty
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $block->block_id          ?? '—',
                    $block->driver_name       ?? '—',
                    $block->block_start       ? Carbon::parse($block->block_start)->format('m/d/Y H:i')      : '—',
                    $block->block_end         ? Carbon::parse($block->block_end)->format('m/d/Y H:i')        : '—',
                    $block->rejection_datetime ? Carbon::parse($block->rejection_datetime)->format('m/d/Y H:i') : '—',
                    $this->humanBlockBucket($block->rejection_bucket),
                    // Load fields — empty
                    '',
                    '',
                    '',
                ]));
            } elseif ($load) {
                fputcsv($file, array_merge($baseRow, [
                    // Advanced block fields — empty
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    // Block fields — empty
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $load->load_id             ?? '—',
                    $load->origin_yard_arrival ? Carbon::parse($load->origin_yard_arrival)->format('m/d/Y H:i') : '—',
                    $this->humanLoadBucket($load->rejection_bucket),
                ]));
            } else {
                // Rejection with no sub-record (edge case)
                fputcsv($file, array_merge($baseRow, array_fill(0, 15, '—')));
            }
        }

        fclose($file);

        return Response::download($filePath)->deleteFileAfterSend(true);
    }

    // ─────────────────────────────────────────────────────────
    // HUMAN-READABLE HELPERS
    // ─────────────────────────────────────────────────────────

    private function humanRejectionType(Rejection $rejection): string
    {
        if ($rejection->advancedRejectedBlock->isNotEmpty()) return 'Advanced Rejection';
        if ($rejection->rejectedBlock->isNotEmpty())         return 'Rejected Block';
        if ($rejection->rejectedLoad->isNotEmpty())          return 'Rejected Load';
        return '—';
    }

    private function humanBlockBucket(?string $bucket): string
    {
        return match ($bucket) {
            'more_than_24' => '24+ hours before start',
            'less_than_24' => 'Less than 24 hours before start',
            default        => $bucket ?? '—',
        };
    }

    private function humanLoadBucket(?string $bucket): string
    {
        return match ($bucket) {
            'rejected_after_start_time'               => 'Rejected after start time',
            'rejected_0_6_hours_before_start_time'    => 'Rejected 0–6 hours before start',
            'rejected_6_plus_hours_before_start_time' => 'Rejected 6+ hours before start',
            default                                   => $bucket ?? '—',
        };
    }

    private function humanDisputed(?string $disputed): string
    {
        return match ($disputed) {
            'none'    => 'None',
            'pending' => 'Pending',
            'won'     => 'Won',
            'lost'    => 'Lost',
            default   => '—',
        };
    }
}
