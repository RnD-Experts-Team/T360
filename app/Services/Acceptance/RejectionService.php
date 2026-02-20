<?php

namespace App\Services\Acceptance;

use App\Models\Rejection;
use Illuminate\Support\Facades\Auth;
use App\Models\Tenant;
use App\Services\Filtering\FilteringService;
use Carbon\Carbon;
use App\Services\Summaries\RejectionBreakdownService;
use App\Models\RejectedBlock;
use App\Models\RejectedLoad;
use App\Models\AdvancedRejectedBlock;

/**
 * Class RejectionService
 *
 * Contains business logic for rejection management and reason code operations.
 *
 * Created manually: touch app/Services/RejectionService.php
 */
class RejectionService
{
    protected FilteringService $filteringService;
    protected RejectionBreakdownService $rejectionBreakdownService;

    /**
     * Constructor.
     *
     * @param FilteringService $filteringService Service for filtering and pagination.
     * @param RejectionBreakdownService $rejectionBreakdownService Service for rejection breakdown data.
     */
    public function __construct(
        FilteringService $filteringService,
        RejectionBreakdownService $rejectionBreakdownService
    ) {
        $this->filteringService = $filteringService;
        $this->rejectionBreakdownService = $rejectionBreakdownService;
    }

    /**
     * Get rejection data for the index view.
     *
     * @return array
     */
    public function getRejectionsIndex(): array
    {
        $user        = Auth::user();
        $isSuperAdmin = is_null($user->tenant_id);

        $dateFilter = $this->filteringService->getDateFilter();
        $perPage    = $this->filteringService->getPerPage();

        $query = Rejection::with([
            'tenant',
            'rejectedLoad',
            'rejectedBlock',
            'advancedRejectedBlock',
        ]);
        $dateRange = [];
        $query = $this->filteringService->applyDateFilter($query, $dateFilter, 'date', $dateRange);

        $request = request();

        // --- rejection_reason filter (default: only with reason) ---
        $reasonFilter = $request->input('rejectionReasonFilter', 'with_reason');
        if ($reasonFilter === 'with_reason') {
            $query->whereNotNull('rejection_reason')->where('rejection_reason', '!=', '');
        } elseif ($reasonFilter === 'without_reason') {
            $query->where(function ($q) {
                $q->whereNull('rejection_reason')->orWhere('rejection_reason', '');
            });
        }
        // 'all' → no filter

        // --- Type filter: derived from existence in sub-tables ---
        if ($request->filled('rejectionType')) {
            $type = $request->input('rejectionType');
            if ($type === 'advanced_block') {
                $query->whereHas('advancedRejectedBlocks');
            } elseif ($type === 'block') {
                $query->whereHas('rejectedBlocks');
            } elseif ($type === 'load') {
                $query->whereHas('rejectedLoads');
            }
        }

        // --- Driver name search across sub-tables ---
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereHas('rejectedBlocks', function ($sq) use ($search) {
                    $sq->whereRaw('LOWER(driver_name) LIKE ?', ["%{$search}%"]);
                })->orWhereHas('rejectedLoads', function ($sq) use ($search) {
                    $sq->whereRaw('LOWER(driver_name) LIKE ?', ["%{$search}%"]);
                });
            });
        }

        // --- Disputed filter ---
        if ($request->filled('disputed')) {
            $query->where('disputed', $request->input('disputed'));
        }

        // --- Carrier controllable filter ---
        if ($request->filled('carrierControllable')) {
            $query->where('carrier_controllable', filter_var($request->input('carrierControllable'), FILTER_VALIDATE_BOOLEAN));
        }

        // --- Driver controllable filter ---
        if ($request->filled('driverControllable')) {
            $query->where('driver_controllable', filter_var($request->input('driverControllable'), FILTER_VALIDATE_BOOLEAN));
        }

        // --- Tenant filter for non-super-admin ---
        if (!$isSuperAdmin) {
            $query->where('tenant_id', $user->tenant_id);
        } elseif ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        $rejections = $query->latest()->paginate($perPage);

        // Week numbers
        $weekNumber      = null;
        $startWeekNumber = null;
        $endWeekNumber   = null;
        $year            = null;

        if (!empty($dateRange) && isset($dateRange['start'])) {
            $startDate = Carbon::parse($dateRange['start']);
            $year      = $startDate->year;

            if (in_array($dateFilter, ['current-week'])) {
                $weekNumber      = $this->weekNumberSundayStart($startDate);
                $startWeekNumber = $endWeekNumber = null;
            } elseif (in_array($dateFilter, ['6w', 'quarterly'])) {
                $weekNumber      = null;
                $startWeekNumber = $this->weekNumberSundayStart($startDate);
                $endWeekNumber   = isset($dateRange['end'])
                    ? $this->weekNumberSundayStart(Carbon::parse($dateRange['end']))
                    : $startWeekNumber;
            }
        }

        $rejectionBreakdown = $this->rejectionBreakdownService->getRejectionBreakdownDetailsPage(
            $dateRange['start'] ?? null,
            $dateRange['end'] ?? null
        );

        $lineChartData = $this->rejectionBreakdownService->getLineChartData(
            $dateRange['start'] ?? null,
            $dateRange['end'] ?? null
        );

        $filters = [
            'search'               => (string) $request->input('search', ''),
            'rejectionType'        => (string) $request->input('rejectionType', ''),
            'disputed'             => (string) $request->input('disputed', ''),
            'carrierControllable'  => (string) $request->input('carrierControllable', ''),
            'driverControllable'   => (string) $request->input('driverControllable', ''),
            'rejectionReasonFilter' => (string) $request->input('rejectionReasonFilter', 'with_reason'),
        ];

        $permissions = Auth::user()->getAllPermissions();

        return [
            'rejections'          => $rejections,
            'tenantSlug'          => $isSuperAdmin ? null : $user->tenant->slug,
            'isSuperAdmin'        => $isSuperAdmin,
            'tenants'             => $isSuperAdmin ? Tenant::all() : [],
            'dateFilter'          => $dateFilter,
            'dateRange'           => $dateRange,
            'perPage'             => $perPage,
            'weekNumber'          => $weekNumber,
            'startWeekNumber'     => $startWeekNumber,
            'endWeekNumber'       => $endWeekNumber,
            'year'                => $year,
            'rejection_breakdown' => $rejectionBreakdown,
            'line_chart_data'     => $lineChartData['chartData'] ?? [],
            'average_acceptance'  => $lineChartData['averageAcceptance'] ?? null,
            'filters'             => $filters,
            'permissions'         => $permissions,
        ];
    }

    /**
     * Get the week‐of‐year for a Carbon date, where weeks run Sunday → Saturday.
     *
     * @param  Carbon  $date
     * @return int
     */
    private function weekNumberSundayStart(Carbon $date): int
    {

        // 1..366
        $dayOfYear   = $date->dayOfYear;

        // 0=Sunday, …, 6=Saturday for Jan 1
        $firstDayDow = $date->copy()
            ->startOfYear()
            ->dayOfWeek;
        // shift so weeks bound on Sunday, then ceil
        return (int) ceil(($dayOfYear + $firstDayDow) / 7);
    }
    /**
     * Create a new rejection.
     *
     * @param array $data
     * @return void
     */
    public function createRejection(array $data)
    {
        $user = Auth::user();
        $data['tenant_id'] = is_null($user->tenant_id) ? $data['tenant_id'] : $user->tenant_id;

        $type = $data['type'];

        // Calculate penalty based on type
        $data['penalty'] = $this->calculatePenalty($type, $data);

        $rejection = Rejection::create([
            'tenant_id'            => $data['tenant_id'],
            'date'                 => $data['date'],
            'penalty'              => $data['penalty'],
            'disputed'             => $data['disputed'],
            'carrier_controllable' => $data['carrier_controllable'],
            'driver_controllable'  => $data['driver_controllable'],
            'rejection_reason'     => $data['rejection_reason'] ?? null,
        ]);

        $this->createSubRecord($rejection, $type, $data);
    }


    /**
     * Update an existing rejection.
     *
     * @param int $id
     * @param array $data
     * @return void
     */
    public function updateRejection($id, array $data)
    {
        $user = Auth::user();
        $data['tenant_id'] = is_null($user->tenant_id) ? $data['tenant_id'] : $user->tenant_id;

        $type = $data['type'];

        $data['penalty'] = $this->calculatePenalty($type, $data);

        $rejection = Rejection::findOrFail($id);

        $rejection->update([
            'tenant_id'            => $data['tenant_id'],
            'date'                 => $data['date'],
            'penalty'              => $data['penalty'],
            'disputed'             => $data['disputed'],
            'carrier_controllable' => $data['carrier_controllable'],
            'driver_controllable'  => $data['driver_controllable'],
            'rejection_reason'     => $data['rejection_reason'] ?? null,
        ]);

        // Remove old sub-records and recreate
        $rejection->advancedRejectedBlock()->delete();
        $rejection->rejectedBlock()->delete();
        $rejection->rejectedLoad()->delete();

        $this->createSubRecord($rejection, $type, $data);
    }


    /**
     * Delete a rejection.
     *
     * @param int $id
     * @return void
     */
    public function deleteRejection($id)
    {
        $rejection = Rejection::findOrFail($id);
        $rejection->delete();
    }


    /**
     * Delete multiple rejection records.
     *
     * @param array $ids Array of rejection IDs to delete
     * @return void
     */
    public function deleteMultipleRejections(array $ids)
    {
        if (empty($ids)) {
            return;
        }
        $query = Rejection::whereIn('id', $ids);

        $user = Auth::user();
        if (!is_null($user->tenant_id)) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $query->delete();
    }

    private function calculatePenalty(string $type, array $data): float
    {
        if ($type === 'advanced_block') {
            $impactedBlocks = (int) ($data['impacted_blocks'] ?? 0);
            return round(0.85 * $impactedBlocks, 2);
        }

        if ($type === 'block') {
            $blockStart        = Carbon::parse($data['block_start']);
            $rejectionDatetime = Carbon::parse($data['rejection_datetime']);
            $hoursBeforeStart  = $rejectionDatetime->diffInHours($blockStart, false);

            // If rejection happened after block start, treat as 0 hours (edge case)
            if ($hoursBeforeStart < 0) {
                $hoursBeforeStart = 0;
            }

            return $hoursBeforeStart < 24 ? 4 : 1;
        }

        if ($type === 'load') {
            return match ($data['load_rejection_bucket']) {
                'rejected_after_start_time'                => 8,
                'rejected_0_6_hours_before_start_time'     => 4,
                'rejected_6_plus_hours_before_start_time'  => 1,
                default                                    => 1,
            };
        }

        return 0;
    }

    private function createSubRecord(Rejection $rejection, string $type, array $data): void
    {
        if ($type === 'advanced_block') {
            $rejection->advancedRejectedBlock()->create([
                'week_start'      => $data['week_start'],
                'week_end'        => $data['week_end'],
                'impacted_blocks' => $data['impacted_blocks'],
                'expected_blocks' => $data['expected_blocks'],
                'advance_block_rejection_id' => $data['advance_block_rejection_id'],
            ]);
        } elseif ($type === 'block') {
            $rejection->rejectedBlock()->create([
                'driver_name'        => $data['block_driver_name'] ?? null,
                'block_start'        => $data['block_start'],
                'block_end'          => $data['block_end'],
                'rejection_datetime' => $data['rejection_datetime'],
                'rejection_bucket'   => Carbon::parse($data['rejection_datetime'])
                    ->diffInHours(Carbon::parse($data['block_start']), false) < 24
                    ? 'less_than_24'
                    : 'more_than_24',
                'block_id' => $data['block_id'],
            ]);
        } elseif ($type === 'load') {
            $rejection->rejectedLoad()->create([
                'driver_name'          => $data['load_driver_name'] ?? null,
                'origin_yard_arrival'  => $data['origin_yard_arrival'],
                'rejection_bucket'     => $data['load_rejection_bucket'],
                'load_id'             => $data['load_id'],
            ]);
        }
    }
}
