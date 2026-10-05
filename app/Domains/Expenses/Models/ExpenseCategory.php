<?php
namespace App\Domains\Expenses\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class ExpenseCategory extends Model { use HasUuids; protected $fillable=['business_id','name','description','is_active']; protected function casts(): array { return ['is_active'=>'boolean']; } }
