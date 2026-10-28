<?php

namespace App\Domains\Receipt\Controllers;

use App\Domains\Receipt\Models\Receipt;
use App\Domains\Receipt\Services\ReceiptAuditService;
use App\Domains\Receipt\Renderers\ReceiptHtmlRenderer;
use App\Domains\Receipt\Renderers\ReceiptPdfRenderer;
use App\Domains\Receipt\Resources\ReceiptResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReceiptController extends Controller
{
    /**
     * List receipts belonging to the current business.
     */

    public function index(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        $query = Receipt::query()
            ->where('business_id', $business->id)
            ->whereHas('sale', function ($saleQuery) use ($business, $branch) {
                $saleQuery
                    ->where('business_id', $business->id)
                    ->where('branch_id', $branch->id);
            })
            ->with([
                'sale',
                'issuedBy:id,name,email',
            ])
            ->latest('issued_at');

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());

            $query->where(function ($receiptQuery) use ($search) {
                $receiptQuery
                    ->where('receipt_number', 'ilike', "%{$search}%")
                    ->orWhereHas('sale', function ($saleQuery) use ($search) {
                        $saleQuery
                            ->where('payment_method', 'ilike', "%{$search}%")
                            ->orWhere('payment_status', 'ilike', "%{$search}%");
                    })
                    ->orWhereHas('issuedBy', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'ilike', "%{$search}%");
                    });
            });
        }

        if ($request->filled('start_date')) {
            $query->whereDate(
                'issued_at',
                '>=',
                $request->date('start_date')
            );
        }

        if ($request->filled('end_date')) {
            $query->whereDate(
                'issued_at',
                '<=',
                $request->date('end_date')
            );
        }


        $perPage = min(
            max($request->integer('per_page', 20), 1),
            100
        );

        $receipts = $query->paginate($perPage);

        $receipts->setCollection(
            $receipts->getCollection()->map(
                fn(Receipt $receipt) => (new ReceiptResource($receipt))
                    ->resolve($request)
            )
        );

        return response()->json([
            'success' => true,
            'data' => $receipts,
        ]);
    }


    /**
     * Display one receipt.
     */
    public function show(
        Request $request,
        Receipt $receipt
    ): JsonResponse {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        $this->assertReceiptBelongsToContext(
            $receipt,
            $business,
            $branch
        );

        $receipt->load([
            'sale',
            'issuedBy:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'data' => new ReceiptResource($receipt),
        ]);
    }

    /**
     * Render a receipt as printable HTML.
     */
    public function print(
        Request $request,
        Receipt $receipt,
        ReceiptHtmlRenderer $renderer,
        ReceiptAuditService $auditService
    ): Response {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        $this->assertReceiptBelongsToContext(
            $receipt,
            $business,
            $branch
        );

        $format = $request->query(
            'format',
            '80mm'
        );

        abort_unless(
            in_array(
                $format,
                ['58mm', '80mm', 'a4'],
                true
            ),
            422,
            'Unsupported receipt format.'
        );

        $auditService->record($business, 'receipt_printed', ['format' => $format], $receipt, null, $request->user());

        $html = $renderer->render(
            $receipt,
            $format
        );

        return response(
            $html,
            200,
            [
                'Content-Type' =>
                'text/html; charset=UTF-8',

                'Content-Disposition' =>
                'inline; filename="' .
                    $receipt->receipt_number .
                    '.html"',

                'X-Receipt-Number' =>
                $receipt->receipt_number,
            ]
        );
    }

    /**
     * Download a receipt as PDF.
     */
    public function pdf(
        Request $request,
        Receipt $receipt,
        ReceiptPdfRenderer $renderer,
        ReceiptAuditService $auditService
    ): Response {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        $this->assertReceiptBelongsToContext(
            $receipt,
            $business,
            $branch
        );

        /*
         * Only supported receipt formats are accepted.
         */
        $format = $request->query(
            'format',
            '80mm'
        );

        abort_unless(
            in_array(
                $format,
                ['58mm', '80mm', 'a4'],
                true
            ),
            422,
            'Unsupported receipt format.'
        );

        /*
         * Generate the PDF from the immutable receipt
         * snapshot-backed representation.
         */
        $auditService->record($business, 'receipt_pdf_downloaded', ['format' => $format], $receipt, null, $request->user());

        $pdf = $renderer->render(
            $receipt,
            $format
        );

        return $pdf->stream(
            $receipt->receipt_number . '.pdf'
        );
    }

    private function assertReceiptBelongsToContext(
        Receipt $receipt,
        \App\Domains\Organization\Models\Business $business,
        \App\Domains\Organization\Models\Branch $branch
    ): void {
        $receipt->loadMissing('sale');

        abort_unless(
            $receipt->business_id === $business->id
                && $receipt->sale
                && $receipt->sale->business_id === $business->id
                && $receipt->sale->branch_id === $branch->id,
            404
        );
    }
}
