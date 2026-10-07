<?php
namespace App\Domains\Expenses\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateExpenseRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['category_id'=>['sometimes','nullable','uuid','exists:expense_categories,id'],'amount'=>['sometimes','numeric','gt:0'],'description'=>['sometimes','string','max:1000'],'expense_date'=>['sometimes','date'],'payment_method'=>['sometimes','string','max:50'],'reference'=>['sometimes','nullable','string','max:255'],'status'=>['sometimes','in:recorded,voided']]; } }
