<?php
namespace App\Domains\Expenses\Models;
use App\Models\User;
use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Expense extends Model { use HasUuids; protected $fillable=['business_id','category_id','branch_id','user_id','amount','description','expense_date','payment_method','reference','status']; protected function casts(): array { return ['amount'=>'decimal:2','expense_date'=>'date']; } public function business(): BelongsTo { return $this->belongsTo(Business::class); } public function category(): BelongsTo { return $this->belongsTo(ExpenseCategory::class,'category_id'); } public function branch(): BelongsTo { return $this->belongsTo(\App\Domains\Organization\Models\Branch::class); } public function user(): BelongsTo { return $this->belongsTo(User::class); } }
