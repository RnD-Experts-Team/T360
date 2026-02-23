<?php

namespace App\Http\Controllers\Web\Acceptance;

use App\Http\Controllers\Controller;
use App\Services\Acceptance\{RejectionImportExportService, RejectionImportValidationService};
use Illuminate\Http\Request;
use App\Http\Requests\Acceptance\StoreRejectionRequest;
use App\Http\Requests\Acceptance\UpdateRejectionRequest;
use App\Services\Acceptance\RejectionService;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

/**
 * Class RejectionsController
 *
 * This controller manages rejection entries and rejection reason codes.
 * It delegates logic to the RejectionService.
 *
 * Command:
 *   php artisan make:controller Web/RejectionsController
 */
class RejectionsController extends Controller
{
    protected RejectionService $rejectionService;
    protected RejectionImportExportService $rejectionImportExportService;
    protected RejectionImportValidationService $rejectionImportValidationService;

    /**
     * Constructor.
     *
     * @param RejectionService $rejectionService Service for rejection processing.
     * @param RejectionImportExportService $rejectionImportExportService
     * @param RejectionImportValidationService $rejectionImportValidationService
     */
    public function __construct(
        RejectionService $rejectionService,
        RejectionImportExportService $rejectionImportExportService,
        RejectionImportValidationService $rejectionImportValidationService
    ) {
        $this->rejectionService = $rejectionService;
        $this->rejectionImportExportService = $rejectionImportExportService;
        $this->rejectionImportValidationService = $rejectionImportValidationService;
    }

    /**
     * Display a list of rejections.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        $data = $this->rejectionService->getRejectionsIndex();
        return Inertia::render('Rejections/Index', $data);
    }


    /**
     * Store a new rejection.
     *
     * @param StoreRejectionRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreRejectionRequest $request)
    {
        $this->rejectionService->createRejection($request->validated());
        return back();
    }


    /**
     * Update an existing rejection.
     *
     * @param UpdateRejectionRequest $request
     * @param string $tenantSlug
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateRejectionRequest $request, $tenantSlug, $id)
    {
        $this->rejectionService->updateRejection($id, $request->validated());
        return back();
    }


    /**
     * Update a rejection as Admin.
     *
     * @param UpdateRejectionRequest $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateAdmin(UpdateRejectionRequest $request, $id)
    {
        $this->rejectionService->updateRejection($id, $request->validated());
        return back();
    }


    /**
     * Delete a rejection.
     *
     * @param string $tenantSlug
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($tenantSlug, $id)
    {
        $this->rejectionService->deleteRejection($id);
        return back();
    }


    /**
     * Delete a rejection as Admin.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyAdmin($id)
    {
        $this->rejectionService->deleteRejection($id);
        return back();
    }



    /**
     * Delete multiple rejection records.
     *
     * @param Request $request
     * @param string|null $tenantSlug
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyBulk(Request $request, $tenantSlug = null)
    {
        $ids = $request->input('ids', []);
        $this->rejectionService->deleteMultipleRejections($ids);
        return redirect()->back()->with('success', 'Rejections deleted successfully.');
    }


    /**
     * Delete multiple rejection records as Admin.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyBulkAdmin(Request $request)
    {
        $ids = $request->input('ids', []);
        $this->rejectionService->deleteMultipleRejections($ids);
        return redirect()->back()->with('success', 'Rejections deleted successfully.');
    }


    public function validateAdvancedBlockImport(Request $request, $tenantSlug = null)
    {
        $request->validate([
            'file'      => 'required|file|mimes:csv,txt|max:10240',
            'tenant_id' => 'nullable|exists:tenants,id',
        ]);

        try {
            $tenantId = $request->input('tenant_id');
            $results  = $this->rejectionImportValidationService
                ->validateAdvancedBlockCsv($request->file('file'), $tenantId ? (int)$tenantId : null);

            if (isset($results['header_error'])) {
                session()->forget(['acceptance_advanced_block_validation', 'acceptance_advanced_block_file', 'acceptance_advanced_block_tenant']);
                return back()->with('importValidation', ['success' => false, 'header_error' => $results['header_error'], 'results' => $results]);
            }

            session(['acceptance_advanced_block_validation' => $results]);
            session(['acceptance_advanced_block_tenant'     => $tenantId]);

            if (($results['summary']['invalid'] ?? 0) === 0) {
                $path = $request->file('file')->store('temp-imports');
                session(['acceptance_advanced_block_file' => $path]);
            } else {
                session()->forget('acceptance_advanced_block_file');
            }

            return back()->with('importValidation', ['success' => true, 'results' => $results]);
        } catch (\Exception $e) {
            session()->forget(['acceptance_advanced_block_validation', 'acceptance_advanced_block_file', 'acceptance_advanced_block_tenant']);
            return back()->with('importValidation', ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function confirmAdvancedBlockImport(Request $request, $tenantSlug = null)
    {
        try {
            $filePath = session('acceptance_advanced_block_file');
            $tenantId = session('acceptance_advanced_block_tenant');

            if (!$filePath || !Storage::exists($filePath)) {
                return back()->with('error', 'Import session expired. Please upload the file again.');
            }

            $storedFile = Storage::path($filePath);
            $file       = new \Illuminate\Http\UploadedFile($storedFile, basename($filePath), mime_content_type($storedFile), null, true);

            $importRequest = new Request();
            $importRequest->files->set('csv_file', $file);

            $result = $this->rejectionImportExportService->importAdvancedBlocks($importRequest, $tenantId ? (int)$tenantId : null);

            Storage::delete($filePath);
            session()->forget(['acceptance_advanced_block_file', 'acceptance_advanced_block_validation', 'acceptance_advanced_block_tenant']);

            return back()->with('success', "{$result['imported']} advanced block rejections imported. {$result['skipped']} skipped.");
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // BLOCKS IMPORT
    // ─────────────────────────────────────────────────────────

    public function validateBlockImport(Request $request, $tenantSlug = null)
    {
        $request->validate([
            'file'      => 'required|file|mimes:csv,txt|max:10240',
            'tenant_id' => 'nullable|exists:tenants,id',
        ]);

        try {
            $tenantId = $request->input('tenant_id');
            $results  = $this->rejectionImportValidationService
                ->validateBlockCsv($request->file('file'), $tenantId ? (int)$tenantId : null);

            if (isset($results['header_error'])) {
                session()->forget(['acceptance_block_validation', 'acceptance_block_file', 'acceptance_block_tenant']);
                return back()->with('importValidation', ['success' => false, 'header_error' => $results['header_error'], 'results' => $results]);
            }

            session(['acceptance_block_validation' => $results]);
            session(['acceptance_block_tenant'     => $tenantId]);

            if (($results['summary']['invalid'] ?? 0) === 0) {
                $path = $request->file('file')->store('temp-imports');
                session(['acceptance_block_file' => $path]);
            } else {
                session()->forget('acceptance_block_file');
            }

            return back()->with('importValidation', ['success' => true, 'results' => $results]);
        } catch (\Exception $e) {
            session()->forget(['acceptance_block_validation', 'acceptance_block_file', 'acceptance_block_tenant']);
            return back()->with('importValidation', ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function confirmBlockImport(Request $request, $tenantSlug = null)
    {
        try {
            $filePath = session('acceptance_block_file');
            $tenantId = session('acceptance_block_tenant');

            if (!$filePath || !Storage::exists($filePath)) {
                return back()->with('error', 'Import session expired. Please upload the file again.');
            }

            $storedFile = Storage::path($filePath);
            $file       = new \Illuminate\Http\UploadedFile($storedFile, basename($filePath), mime_content_type($storedFile), null, true);

            $importRequest = new Request();
            $importRequest->files->set('csv_file', $file);

            $result = $this->rejectionImportExportService->importBlocks($importRequest, $tenantId ? (int)$tenantId : null);

            Storage::delete($filePath);
            session()->forget(['acceptance_block_file', 'acceptance_block_validation', 'acceptance_block_tenant']);

            return back()->with('success', "{$result['imported']} block rejections imported. {$result['skipped']} skipped.");
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // LOADS IMPORT
    // ─────────────────────────────────────────────────────────

    public function validateLoadImport(Request $request, $tenantSlug = null)
    {
        $request->validate([
            'file'       => 'required_without:trips_file|file|mimes:csv,txt|max:10240',
            'trips_file' => 'nullable|file|mimes:csv,txt|max:51200',
            'tenant_id'  => 'nullable|exists:tenants,id',
        ]);

        try {
            $tenantId = $request->input('tenant_id');

            // If only trips file was uploaded, skip loads validation
            if (!$request->hasFile('file') && $request->hasFile('trips_file')) {
                session(['acceptance_load_trips_only'  => true]);
                session(['acceptance_load_tenant'      => $tenantId]);

                $tripsPath = $request->file('trips_file')->store('temp-imports');
                session(['acceptance_load_trips_file'  => $tripsPath]);

                return back()->with('importValidation', [
                    'success'    => true,
                    'trips_only' => true,
                    'results'    => ['summary' => ['total' => -1, 'valid' => 0, 'invalid' => 0]],
                ]);
            }

            $results = $this->rejectionImportValidationService
                ->validateLoadCsv($request->file('file'), $tenantId ? (int)$tenantId : null);

            if (isset($results['header_error'])) {
                session()->forget(['acceptance_load_validation', 'acceptance_load_file', 'acceptance_load_trips_file', 'acceptance_load_tenant', 'acceptance_load_trips_only']);
                return back()->with('importValidation', ['success' => false, 'header_error' => $results['header_error'], 'results' => $results]);
            }

            session(['acceptance_load_validation'  => $results]);
            session(['acceptance_load_tenant'      => $tenantId]);
            session(['acceptance_load_trips_only'  => false]);

            if (($results['summary']['invalid'] ?? 0) === 0) {
                $loadsPath = $request->file('file')->store('temp-imports');
                session(['acceptance_load_file' => $loadsPath]);

                if ($request->hasFile('trips_file')) {
                    $tripsPath = $request->file('trips_file')->store('temp-imports');
                    session(['acceptance_load_trips_file' => $tripsPath]);
                } else {
                    session()->forget('acceptance_load_trips_file');
                }
            } else {
                session()->forget(['acceptance_load_file', 'acceptance_load_trips_file']);
            }

            return back()->with('importValidation', ['success' => true, 'results' => $results]);
        } catch (\Exception $e) {
            session()->forget(['acceptance_load_validation', 'acceptance_load_file', 'acceptance_load_trips_file', 'acceptance_load_tenant', 'acceptance_load_trips_only']);
            return back()->with('importValidation', ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function confirmLoadImport(Request $request, $tenantSlug = null)
    {
        try {
            $tenantId  = session('acceptance_load_tenant');
            $tripsOnly = session('acceptance_load_trips_only', false);
            if ($tripsOnly) {
                $tripsPath = session('acceptance_load_trips_file');
                if (!$tripsPath || !Storage::exists($tripsPath)) {
                    return back()->with('error', 'Import session expired. Please upload the file again.');
                }

                $tripsStored = Storage::path($tripsPath);
                $tripsFile   = new \Illuminate\Http\UploadedFile($tripsStored, basename($tripsPath), mime_content_type($tripsStored), null, true);

                $importRequest = new Request();
                $importRequest->files->set('trips_file', $tripsFile);
                $result = $this->rejectionImportExportService->importTripsOnly($importRequest, $tenantId ? (int)$tenantId : null);

                Storage::delete($tripsPath);
                session()->forget(['acceptance_load_trips_file', 'acceptance_load_tenant', 'acceptance_load_trips_only']);

                return back()->with('success', "{$result['updated']} driver names updated from trips.");
            }
            $loadsPath = session('acceptance_load_file');
            if (!$loadsPath || !Storage::exists($loadsPath)) {
                return back()->with('error', 'Import session expired. Please upload the file again.');
            }

            $loadsStored = Storage::path($loadsPath);
            $loadsFile   = new \Illuminate\Http\UploadedFile($loadsStored, basename($loadsPath), mime_content_type($loadsStored), null, true);

            $importRequest = new Request();
            $importRequest->files->set('csv_file', $loadsFile);

            $tripsPath = session('acceptance_load_trips_file');
            if ($tripsPath && Storage::exists($tripsPath)) {
                $tripsStored = Storage::path($tripsPath);
                $tripsFile   = new \Illuminate\Http\UploadedFile($tripsStored, basename($tripsPath), mime_content_type($tripsStored), null, true);
                $importRequest->files->set('trips_file', $tripsFile);
            }

            $result = $this->rejectionImportExportService->importLoads($importRequest, $tenantId ? (int)$tenantId : null);

            Storage::delete($loadsPath);
            if ($tripsPath) Storage::delete($tripsPath);
            session()->forget(['acceptance_load_file', 'acceptance_load_trips_file', 'acceptance_load_validation', 'acceptance_load_tenant', 'acceptance_load_trips_only']);

            return back()->with('success', "{$result['imported']} load rejections imported. {$result['skipped']} skipped.");
        } catch (\Exception $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // ERROR REPORT DOWNLOAD
    // ─────────────────────────────────────────────────────────

    public function downloadErrorReport(Request $request, $tenantSlug = null)
    {
        try {
            // Try all three session keys in order
            $results = session('acceptance_block_validation')
                ?? session('acceptance_load_validation')
                ?? session('acceptance_advanced_block_validation');

            if (!$results || empty($results['invalid'])) {
                return back()->with('error', 'No validation errors to download.');
            }

            $filePath = $this->rejectionImportValidationService->generateErrorReport($results['invalid']);

            return response()->download($filePath)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to generate error report: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // EXPORT
    // ─────────────────────────────────────────────────────────

    public function export($tenantSlug = null)
    {
        return $this->rejectionImportExportService->exportRejections();
    }

    public function exportAdmin()
    {
        return $this->rejectionImportExportService->exportRejections();
    }
}
