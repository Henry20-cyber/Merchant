<?php
namespace App\Domains\Expenses\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreExpenseRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['category_id'=>['nullable','uuid','exists:expense_categories,id'],'amount'=>['required','numeric','gt:0'],'description'=>['required','string','max:1000'],'expense_date'=>['required','date'],'payment_method'=>['required','string','max:50'],'reference'=>['nullable','string','max:255'],'status'=>['sometimes','in:recorded,voided']]; } }
