<?php
namespace App\Domains\Expenses\Controllers;
use App\Http\Controllers\Controller;
use App\Domains\Expenses\Requests\StoreExpenseRequest;
use App\Domains\Expenses\Requests\UpdateExpenseRequest;
use App\Domains\Expenses\Requests\StoreExpenseCategoryRequest;
use App\Domains\Expenses\Services\ExpenseService;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Organization\Services\BusinessContextService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
class ExpenseController extends Controller { private function business(Request $r){return app(BusinessContextService::class)->current($r->user());} public function index(Request $r,ExpenseService $s):JsonResponse{return response()->json(['success'=>true,'data'=>$s->list($this->business($r),$r->only(['start_date','end_date','category_id','search','per_page']))]);} public function store(StoreExpenseRequest $r,ExpenseService $s):JsonResponse{return response()->json(['success'=>true,'data'=>$s->create($this->business($r),$r->user(),$r->validated())],201);} public function update(UpdateExpenseRequest $r,Expense $expense,ExpenseService $s):JsonResponse{return response()->json(['success'=>true,'data'=>$s->update($this->business($r),$expense,$r->validated())]);} public function destroy(Request $r,Expense $expense,ExpenseService $s):JsonResponse{$s->delete($this->business($r),$expense);return response()->json(['success'=>true,'message'=>'Expense deleted.']);} public function categories(Request $r,ExpenseService $s):JsonResponse{return response()->json(['success'=>true,'data'=>$s->categories($this->business($r))]);} public function storeCategory(StoreExpenseCategoryRequest $r,ExpenseService $s):JsonResponse{return response()->json(['success'=>true,'data'=>$s->createCategory($this->business($r),$r->validated())],201);} public function summary(Request $r,ExpenseService $s):JsonResponse{return response()->json(['success'=>true,'data'=>$s->summary($this->business($r),$r->input('start_date'),$r->input('end_date'))]);} }
