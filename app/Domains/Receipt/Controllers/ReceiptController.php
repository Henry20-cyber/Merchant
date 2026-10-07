<?php

namespace App\Domains\Receipt\Controllers;

use App\Domains\Receipt\Models\Receipt;
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

        $receipts = Receipt::query()
            ->where('business_id', $business->id)
            ->whereHas('sale', function ($query) use ($business, $branch) {
                $query
                    ->where('business_id', $business->id)
                    ->where('branch_id', $branch->id);
            })
            ->with([
                'sale',
                'issuedBy:id,name,email',
            ])
            ->latest('issued_at')
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => ReceiptResource::collection(
                $receipts
            ),
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
        ReceiptHtmlRenderer $renderer
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
        ReceiptPdfRenderer $renderer
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