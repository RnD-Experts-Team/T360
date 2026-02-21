<?php

namespace App\Services\Summaries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class RejectionBreakdownService
{
    /**
     * Get rejection breakdown by driver
     */
    public function getRejectionsByDriver($startDate, $endDate)
    {
        // Blocks
        $blockQuery = DB::table('rejections')
            ->join('rejected_blocks', 'rejected_blocks.rejection_id', '=', 'rejections.id')
            ->selectRaw("
            rejected_blocks.driver_name,
            COUNT(*) as total_block_rejections,
            SUM(rejections.penalty) as total_block_penalty
        ")
            ->whereBetween('rejections.date', [$startDate, $endDate])
            ->where('rejections.driver_controllable', true);  // Add check for driver_controllable

        $this->applyTenantFilter($blockQuery, 'rejections');
        $blockResults = $blockQuery->groupBy('rejected_blocks.driver_name')->get()->keyBy('driver_name');

        // Loads
        $loadQuery = DB::table('rejections')
            ->join('rejected_loads', 'rejected_loads.rejection_id', '=', 'rejections.id')
            ->selectRaw("
            rejected_loads.driver_name,
            COUNT(*) as total_load_rejections,
            SUM(rejections.penalty) as total_load_penalty
        ")
            ->whereBetween('rejections.date', [$startDate, $endDate])
            ->where('rejections.driver_controllable', true);  // Add check for driver_controllable

        $this->applyTenantFilter($loadQuery, 'rejections');
        $loadResults = $loadQuery->groupBy('rejected_loads.driver_name')->get()->keyBy('driver_name');

        // Merge
        $allDrivers = $blockResults->keys()->merge($loadResults->keys())->unique();

        return $allDrivers->map(function ($driverName) use ($blockResults, $loadResults) {
            $block = $blockResults->get($driverName);
            $load  = $loadResults->get($driverName);

            return (object) [
                'driver_name'            => $driverName,
                'total_rejections'       => ($block->total_block_rejections ?? 0) + ($load->total_load_rejections ?? 0),
                'total_penalty'          => ($block->total_block_penalty ?? 0) + ($load->total_load_penalty ?? 0),
                'total_block_rejections' => $block->total_block_rejections ?? 0,
                'total_block_penalty'    => $block->total_block_penalty ?? 0,
                'total_load_rejections'  => $load->total_load_rejections ?? 0,
                'total_load_penalty'     => $load->total_load_penalty ?? 0,
            ];
        })->values();
    }

    /**
     * Get rejection breakdown by reason code
     */
    public function getRejectionsByReason($startDate, $endDate)
    {
        $query = DB::table('rejections')
            ->selectRaw("
            rejection_reason,
            COUNT(*) as total_rejections,
            SUM(penalty) as total_penalty
        ")
            ->whereNotNull('rejection_reason')
            ->where('rejection_reason', '!=', '')
            ->whereBetween('date', [$startDate, $endDate]);

        $this->applyTenantFilter($query);

        return $query->groupBy('rejection_reason')->get();
    }


    /**
     * Get count of all rejections and breakdown by rejection category
     */
    public function getRejectionsCategoryBreakdown($startDate, $endDate)
    {
        // Total rejections and penalty
        $totalQuery = DB::table('rejections')
            ->selectRaw("COUNT(*) as total_rejections, SUM(penalty) as total_penalty")
            ->whereBetween('date', [$startDate, $endDate])
            ->where('driver_controllable', true);  // Add check for driver_controllable

        $this->applyTenantFilter($totalQuery);
        $total = $totalQuery->first();

        // Advanced blocks breakdown
        $advancedQuery = DB::table('rejections')
            ->join('advanced_rejected_blocks', 'advanced_rejected_blocks.rejection_id', '=', 'rejections.id')
            ->selectRaw("COUNT(*) as advanced_rejection_count, SUM(rejections.penalty) as advanced_rejection_penalty")
            ->whereBetween('rejections.date', [$startDate, $endDate])
            ->where('rejections.driver_controllable', true);  // Add check for driver_controllable

        $this->applyTenantFilter($advancedQuery, 'rejections');
        $advanced = $advancedQuery->first();

        // Blocks by bucket
        $blockQuery = DB::table('rejections')
            ->join('rejected_blocks', 'rejected_blocks.rejection_id', '=', 'rejections.id')
            ->selectRaw("
            COUNT(*) as total_block_rejections,
            SUM(CASE WHEN rejected_blocks.rejection_bucket = 'less_than_24' THEN 1 ELSE 0 END) as less_than_24_count,
            SUM(CASE WHEN rejected_blocks.rejection_bucket = 'more_than_24' THEN 1 ELSE 0 END) as more_than_24_count,
            SUM(CASE WHEN rejected_blocks.rejection_bucket = 'less_than_24' THEN rejections.penalty ELSE 0 END) as less_than_24_penalty,
            SUM(CASE WHEN rejected_blocks.rejection_bucket = 'more_than_24' THEN rejections.penalty ELSE 0 END) as more_than_24_penalty
        ")
            ->whereBetween('rejections.date', [$startDate, $endDate])
            ->where('rejections.driver_controllable', true);  // Add check for driver_controllable

        $this->applyTenantFilter($blockQuery, 'rejections');
        $blocks = $blockQuery->first();

        // Loads by bucket
        $loadQuery = DB::table('rejections')
            ->join('rejected_loads', 'rejected_loads.rejection_id', '=', 'rejections.id')
            ->selectRaw("
            COUNT(*) as total_load_rejections,
            SUM(CASE WHEN rejected_loads.rejection_bucket = 'rejected_after_start_time' THEN 1 ELSE 0 END) as after_start_count,
            SUM(CASE WHEN rejected_loads.rejection_bucket = 'rejected_0_6_hours_before_start_time' THEN 1 ELSE 0 END) as within_6_count,
            SUM(CASE WHEN rejected_loads.rejection_bucket = 'rejected_6_plus_hours_before_start_time' THEN 1 ELSE 0 END) as more_than_6_count,
            SUM(CASE WHEN rejected_loads.rejection_bucket = 'rejected_after_start_time' THEN rejections.penalty ELSE 0 END) as after_start_penalty,
            SUM(CASE WHEN rejected_loads.rejection_bucket = 'rejected_0_6_hours_before_start_time' THEN rejections.penalty ELSE 0 END) as within_6_penalty,
            SUM(CASE WHEN rejected_loads.rejection_bucket = 'rejected_6_plus_hours_before_start_time' THEN rejections.penalty ELSE 0 END) as more_than_6_penalty
        ")
            ->whereBetween('rejections.date', [$startDate, $endDate])
            ->where('rejections.driver_controllable', true);  // Add check for driver_controllable

        $this->applyTenantFilter($loadQuery, 'rejections');
        $loads = $loadQuery->first();

        return (object) [
            'total_rejections'         => $total->total_rejections ?? 0,
            'total_penalty'            => $total->total_penalty ?? 0,

            // Advanced blocks
            'advanced_rejection_count' => $advanced->advanced_rejection_count ?? 0,
            'advanced_rejection_penalty' => $advanced->advanced_rejection_penalty ?? 0,

            // Blocks
            'total_block_rejections'   => $blocks->total_block_rejections ?? 0,
            'less_than_24_count'       => $blocks->less_than_24_count ?? 0,
            'more_than_24_count'       => $blocks->more_than_24_count ?? 0,
            'less_than_24_penalty'     => $blocks->less_than_24_penalty ?? 0,
            'more_than_24_penalty'     => $blocks->more_than_24_penalty ?? 0,

            // Loads
            'total_load_rejections'    => $loads->total_load_rejections ?? 0,
            'after_start_count'         => $loads->after_start_count ?? 0,
            'within_6_count'           => $loads->within_6_count ?? 0,
            'more_than_6_count'        => $loads->more_than_6_count ?? 0,
            'after_start_penalty'      => $loads->after_start_penalty ?? 0,
            'within_6_penalty'         => $loads->within_6_penalty ?? 0,
            'more_than_6_penalty'      => $loads->more_than_6_penalty ?? 0,
        ];
    }

    /**
     * Get bottom five drivers with highest penalty sum
     */
    public function getBottomFiveDriversByPenalty($startDate, $endDate)
    {
        // Total (blocks + loads combined)
        $blockTotal = DB::table('rejections')
            ->join('rejected_blocks', 'rejected_blocks.rejection_id', '=', 'rejections.id')
            ->selectRaw("rejected_blocks.driver_name, SUM(rejections.penalty) as total_penalty")
            ->whereBetween('rejections.date', [$startDate, $endDate]);
        $this->applyTenantFilter($blockTotal, 'rejections');
        $blockTotal = $blockTotal->groupBy('rejected_blocks.driver_name')->get()->keyBy('driver_name');

        $loadTotal = DB::table('rejections')
            ->join('rejected_loads', 'rejected_loads.rejection_id', '=', 'rejections.id')
            ->selectRaw("rejected_loads.driver_name, SUM(rejections.penalty) as total_penalty")
            ->whereBetween('rejections.date', [$startDate, $endDate]);
        $this->applyTenantFilter($loadTotal, 'rejections');
        $loadTotal = $loadTotal->groupBy('rejected_loads.driver_name')->get()->keyBy('driver_name');

        $allDrivers = $blockTotal->keys()->merge($loadTotal->keys())->unique();
        $combined = $allDrivers->map(function ($name) use ($blockTotal, $loadTotal) {
            return (object) [
                'driver_name'   => $name,
                'total_penalty' => ($blockTotal->get($name)->total_penalty ?? 0)
                    + ($loadTotal->get($name)->total_penalty ?? 0),
            ];
        })->sortByDesc('total_penalty')->take(5)->values();

        // Bottom five by block penalty only
        $bottomFiveBlock = DB::table('rejections')
            ->join('rejected_blocks', 'rejected_blocks.rejection_id', '=', 'rejections.id')
            ->selectRaw("rejected_blocks.driver_name, SUM(rejections.penalty) as total_penalty")
            ->whereBetween('rejections.date', [$startDate, $endDate]);
        $this->applyTenantFilter($bottomFiveBlock, 'rejections');
        $bottomFiveBlock = $bottomFiveBlock->groupBy('rejected_blocks.driver_name')
            ->orderBy('total_penalty', 'desc')
            ->limit(5)
            ->get();

        // Bottom five by load penalty only
        $bottomFiveLoad = DB::table('rejections')
            ->join('rejected_loads', 'rejected_loads.rejection_id', '=', 'rejections.id')
            ->selectRaw("rejected_loads.driver_name, SUM(rejections.penalty) as total_penalty")
            ->whereBetween('rejections.date', [$startDate, $endDate]);
        $this->applyTenantFilter($bottomFiveLoad, 'rejections');
        $bottomFiveLoad = $bottomFiveLoad->groupBy('rejected_loads.driver_name')
            ->orderBy('total_penalty', 'desc')
            ->limit(5)
            ->get();

        return [
            'total' => $combined,
            'block' => $bottomFiveBlock,
            'load'  => $bottomFiveLoad,
        ];
    }


    /**
     * Apply tenant filter to query if user is authenticated
     */
    public function applyTenantFilter($query, $tablePrefix = '')
    {
        if (Auth::check() && Auth::user()->tenant_id !== null) {
            $columnName = $tablePrefix ? "{$tablePrefix}.tenant_id" : 'tenant_id';
            $query->where($columnName, Auth::user()->tenant_id);
        }
    }


    /**
     * Get complete rejection breakdown data for the specified date range
     */
    public function getRejectionBreakdown($startDate, $endDate): array
    {
        return [
            'by_driver' => $this->getRejectionsByDriver($startDate, $endDate),
            'by_reason' => $this->getRejectionsByReason($startDate, $endDate),
        ];
    }


    /**
     * Get rejection breakdown details for the details page
     */
    public function getRejectionBreakdownDetailsPage($startDate, $endDate): array
    {
        return [
            'by_category' => $this->getRejectionsCategoryBreakdown($startDate, $endDate),
            'bottom_five_drivers' => $this->getBottomFiveDriversByPenalty($startDate, $endDate),
        ];
    }

    /**
     * Get line chart data for acceptance performance trends
     * 
     * @param string $startDate The start date for the query
     * @param string $endDate The end date for the query
     * @return array The line chart data
     */
    public function getLineChartData($startDate, $endDate): array
    {
        // Use Carbon for consistent date handling
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        // Determine date filter type based on date range
        $dateFilter = $this->determineDateFilterType($start, $end);

        // Determine grouping based on date filter type
        if ($dateFilter === 'yesterday') {
            // For yesterday, we'll show hourly data if available
            $dateFormat = 'Y-m-d';
            $groupBy = DB::raw('DATE_FORMAT(date, "%Y-%m-%d")');
            $labelFormat = 'H:00'; // Hour format
        } elseif ($dateFilter === 'current-week') {
            // Current week - group by day
            $dateFormat = 'Y-m-d';
            $groupBy = DB::raw('DATE(date)');
            $labelFormat = 'D'; // Day name (Mon, Tue, etc.)
        } elseif ($dateFilter === '6w') {
            // 6 weeks - group by week with weeks starting on Sunday
            $dateFormat = 'Y-W';
            // Use YEARWEEK with mode 0 (weeks starting on Sunday)
            $groupBy = DB::raw('YEARWEEK(date, 6)');
            $labelFormat = '\WW'; // Week number (W1, W2, etc.)
        } else {
            // Quarterly or longer - group by month
            $dateFormat = 'Y-m';
            $groupBy = DB::raw('DATE_FORMAT(date, "%Y-%m")');
            $labelFormat = 'M'; // Month name (Jan, Feb, etc.)
        }
        // Get the average acceptance performance across the entire date range
        $averageQuery = DB::table('performances')
            ->selectRaw('AVG(acceptance) as averageAcceptance')
            ->whereBetween('date', [$startDate, $endDate]);

        $this->applyTenantFilter($averageQuery);
        $averageResult = $averageQuery->first();
        $averageAcceptance = $averageResult ? round($averageResult->averageAcceptance, 1) : null;

        $query = DB::table('performances')
            ->select($groupBy, DB::raw('AVG(acceptance) as acceptancePerformance'))
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy($groupBy)
            ->orderBy($groupBy);

        $this->applyTenantFilter($query);
        $results = $query->get();

        // Format dates based on the determined grouping
        $chartData = $results->map(function ($item) use ($dateFormat, $labelFormat, $dateFilter) {
            // Get the first property (date or yearweek)
            $dateValue = $item->{array_key_first((array)$item)};

            if ($dateFormat === 'Y-m-d') {
                // For daily grouping
                $date = Carbon::parse($dateValue);
                $formattedDate = $date->format($labelFormat);
            } elseif ($dateFormat === 'Y-m-d') {
                // For hourly grouping
                $date = Carbon::parse($dateValue);
                $formattedDate = $date->format($labelFormat);
            } elseif ($dateFormat === 'Y-m') {
                // For monthly grouping
                $date = Carbon::parse($dateValue . '-01');
                $formattedDate = $date->format($labelFormat);
            } else {
                // For weekly grouping
                // Extract year and week from YEARWEEK format (YYYYWW)
                $year = substr($dateValue, 0, 4);
                $week = substr($dateValue, 4);
                $formattedDate = 'W' . $week;
            }

            return [
                'date' => $formattedDate,
                'acceptancePerformance' => round($item->acceptancePerformance, 1)
            ];
        })->toArray();
        return [
            'chartData' => $chartData,
            'averageAcceptance' => $averageAcceptance
        ];
    }

    /**
     * Determine the date filter type based on the date range
     * 
     * @param Carbon $start The start date
     * @param Carbon $end The end date
     * @return string The date filter type (yesterday, current-week, 6w, quarterly, or full)
     */
    private function determineDateFilterType(Carbon $start, Carbon $end): string
    {
        $daysDifference = $start->diffInDays($end);
        $now = Carbon::now();
        $isSunday = $now->dayOfWeek === 0;
        if ($isSunday) {
            $now = $now->copy()->subDays(1);
        }
        $yesterday = Carbon::yesterday();
        $currentWeekStart = $now->copy()->startOfWeek(Carbon::SUNDAY);
        $currentWeekEnd = $now->copy()->endOfWeek(Carbon::SATURDAY);
        $sixWeeksStart = $currentWeekStart->copy()->subWeeks(5);
        // Check if the date range matches yesterday
        if ($start->isSameDay($yesterday) && $end->isSameDay($yesterday)) {
            return 'yesterday';
        }
        // Check if the date range matches current week
        if ($start->isSameDay($currentWeekStart) && $end->isSameDay($currentWeekEnd)) {
            return 'current-week';
        }

        // Check if the date range matches 6 weeks
        if ($start->isSameDay($sixWeeksStart) && $end->isSameDay($currentWeekEnd)) {
            return '6w';
        }

        // Check if the date range is approximately 3 months
        if ($daysDifference >= 85 && $daysDifference <= 95) {
            return 'quarterly';
        }

        // Default to full if none of the above match
        return 'full';
    }

    /**
     * Get complete rejection breakdown data with line chart for the specified date range
     */
    public function getRejectionBreakdownWithChart($startDate, $endDate): array
    {
        $basicData = $this->getRejectionBreakdown($startDate, $endDate);
        $detailsData = $this->getRejectionBreakdownDetailsPage($startDate, $endDate);
        $lineChartData = $this->getLineChartData($startDate, $endDate);

        return array_merge(
            $basicData,
            $detailsData,
            ['lineChartData' => $lineChartData]
        );
    }
}
