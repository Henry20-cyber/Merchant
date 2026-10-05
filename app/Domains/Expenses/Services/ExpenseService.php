<?php
namespace App\Domains\Expenses\Services;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Models\ExpenseCategory;
use App\Domains\Organization\Models\Business;
use App\Models\User;
class ExpenseService {
 public function list(Business $business,array $filters=[]): array { $q=Expense::query()->where('business_id',$business->id)->with(['category:id,name','branch:id,name'])->latest('expense_date')->latest(); if(!empty($filters['start_date']))$q->whereDate('expense_date','>=',$filters['start_date']); if(!empty($filters['end_date']))$q->whereDate('expense_date','<=',$filters['end_date']); if(!empty($filters['category_id']))$q->where('category_id',$filters['category_id']); if(!empty($filters['search']))$q->where(fn($x)=>$x->where('description','ilike','%'.$filters['search'].'%')->orWhere('reference','ilike','%'.$filters['search'].'%')); return $q->paginate(min(max((int)($filters['per_page']??20),1),100))->toArray(); }
 public function create(Business $business,User $user,array $data): Expense { $this->branch($business,$data['branch_id']??null); $this->category($business,$data['category_id']??null); return Expense::create($data+['business_id'=>$business->id,'user_id'=>$user->id,'status'=>$data['status']??'recorded']); }
 public function update(Business $business,Expense $expense,array $data): Expense { abort_unless($expense->business_id===$business->id,404); $this->branch($business,$data['branch_id']??$expense->branch_id); $this->category($business,$data['category_id']??$expense->category_id); $expense->update($data); return $expense->refresh()->load(['category:id,name','branch:id,name']); }
 public function delete(Business $business,Expense $expense): void { abort_unless($expense->business_id===$business->id,404); $expense->delete(); }
 public function categories(Business $business){ return ExpenseCategory::where('business_id',$business->id)->where('is_active',true)->orderBy('name')->get(); }
 public function createCategory(Business $business,array $data): ExpenseCategory { return ExpenseCategory::firstOrCreate(['business_id'=>$business->id,'name'=>$data['name']],['description'=>$data['description']??null,'is_active'=>true]); }
 public function summary(Business $business,?string $start=null,?string $end=null): array { $q=Expense::where('business_id',$business->id)->where('status','recorded'); if($start)$q->whereDate('expense_date','>=',$start);if($end)$q->whereDate('expense_date','<=',$end);$total=(float)$q->sum('amount');$rows=(clone $q)->leftJoin('expense_categories','expense_categories.id','=','expenses.category_id')->selectRaw("COALESCE(expense_categories.name,'Uncategorised') name, SUM(expenses.amount) amount")->groupBy('expense_categories.name')->orderByDesc('amount')->limit(8)->get()->map(fn($r)=>['name'=>$r->name,'amount'=>(float)$r->amount])->values()->all();return ['total'=>$total,'by_category'=>$rows]; }
 private function category(Business $b,?string $id):void{if($id&&!ExpenseCategory::where('id',$id)->where('business_id',$b->id)->exists())abort(422,'Invalid expense category.');}
 private function branch(Business $b,?string $id):void{if($id&&!$b->branches()->where('id',$id)->exists())abort(422,'Invalid business branch.');}
}
