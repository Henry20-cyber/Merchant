<?php

namespace App\Domains\Credit\Http\Controllers;

use App\Domains\Credit\Models\Credit;
use App\Domains\Credit\Services\CreditService;
use App\Domains\Organization\Services\BusinessContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CreditController
{
  public function __construct(
    private CreditService $creditService,
  ) {}

  public function index(
    Request $request,
    BusinessContextService $businessContext,
  ): JsonResponse {
    $business = $businessContext->current($request->user());

    if (! $business) {
      return response()->json([
        'success' => false,
        'message' => 'Business context is required.',
      ], 400);
    }

    $query = Credit::query()
      ->with([
        'customer:id,name,phone',
        'sale:id,business_id,total,payment_status,created_at',
      ])
      ->where('business_id', $business->id)
      ->latest();

    if ($request->filled('status')) {
      $query->where('status', $request->input('status'));
    }

    if ($request->filled('customer_id')) {
      $query->where('customer_id', $request->input('customer_id'));
    }
    

    $credits = $query->get()->map(function (Credit $credit) {
      return [
        'id' => $credit->id,
        'customer' => $credit->customer,
        'sale' => $credit->sale,
        'original_amount' => $credit->original_amount,
        'paid_amount' => $this->creditService->amountPaid($credit),
        'outstanding_amount' => $this->creditService->outstandingAmount($credit),
        'due_at' => $credit->due_at,
        'status' => $this->creditService->determineStatus($credit),
        'created_at' => $credit->created_at,
      ];
    });

    return response()->json([
      'success' => true,
      'data' => $credits,
    ]);
  }

  public function show(
    Request $request,
    Credit $credit,
    BusinessContextService $businessContext,
  ): JsonResponse {
    $business = $businessContext->current($request->user());

    if (! $business) {
      return response()->json([
        'success' => false,
        'message' => 'Business context is required.',
      ], 400);
    }

    if ($credit->business_id !== $business->id) {
      return response()->json([
        'success' => false,
        'message' => 'Credit record not found.',
      ], 404);
    }

    $credit->load([
      'customer:id,name,phone',
      'sale:id,business_id,total,payment_method,payment_status,created_at',
      'sale.payments' => fn($query) => $query
        ->latest('paid_at')
        ->latest(),
    ]);

    return response()->json([
      'success' => true,
      'data' => [
        'id' => $credit->id,
        'customer' => $credit->customer,
        'sale' => $credit->sale,
        'original_amount' => $credit->original_amount,
        'paid_amount' => $this->creditService->amountPaid($credit),
        'outstanding_amount' => $this->creditService->outstandingAmount($credit),
        'due_at' => $credit->due_at,
        'status' => $this->creditService->determineStatus($credit),
        'created_at' => $credit->created_at,
        'payments' => $credit->sale->payments,
      ],
    ]);
  }

  public function recordPayment(
    Request $request,
    Credit $credit,
    BusinessContextService $businessContext,
  ): JsonResponse {
    $business = $businessContext->current($request->user());

    if (! $business) {
      return response()->json([
        'success' => false,
        'message' => 'Business context is required.',
      ], 400);
    }

    if ($credit->business_id !== $business->id) {
      return response()->json([
        'success' => false,
        'message' => 'Credit record not found.',
      ], 404);
    }

    $validated = $request->validate([
      'amount' => [
        'required',
        'numeric',
        'gt:0',
      ],
      'method' => [
        'required',
        'string',
        'in:cash,bank_transfer,card,mobile_money,other',
      ],
      'reference' => [
        'nullable',
        'string',
        'max:255',
      ],
      'metadata' => [
        'nullable',
        'array',
      ],
    ]);

    $credit->loadMissing('sale');

    if ($this->creditService->determineStatus($credit) === 'settled') {
      throw ValidationException::withMessages([
        'credit' => 'This credit has already been fully settled.',
      ]);
    }

    $payment = $this->creditService->recordPayment(
      $business,
      $credit,
      [
        'amount' => $validated['amount'],
        'method' => $validated['method'],
        'status' => 'paid',
        'reference' => $validated['reference'] ?? null,
        'metadata' => $validated['metadata'] ?? null,
      ],
    );

    $credit->refresh();
    $credit->load([
      'customer:id,name,phone',
      'sale:id,business_id,total,payment_method,payment_status,created_at',
      'sale.payments' => fn($query) => $query
        ->latest('paid_at')
        ->latest(),
    ]);

    return response()->json([
      'success' => true,
      'message' => 'Credit repayment recorded successfully.',
      'data' => [
        'payment' => $payment,
        'credit' => [
          'id' => $credit->id,
          'customer' => $credit->customer,
          'sale' => $credit->sale,
          'original_amount' => $credit->original_amount,
          'paid_amount' => $this->creditService->amountPaid($credit),
          'outstanding_amount' => $this->creditService->outstandingAmount($credit),
          'due_at' => $credit->due_at,
          'status' => $this->creditService->determineStatus($credit),
        ],
        'payments' => $credit->sale->payments,
      ],
    ]);
  }
}
