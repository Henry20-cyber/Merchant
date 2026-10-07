<?php
namespace App\Domains\Expenses\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreExpenseCategoryRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['name'=>['required','string','max:100'],'description'=>['nullable','string','max:500']]; } }
