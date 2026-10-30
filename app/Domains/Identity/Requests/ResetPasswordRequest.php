<?php
namespace App\Domains\Identity\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
class ResetPasswordRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'token'=>['required','string'],
            'email'=>['required','email','max:255'],
            'password'=>['required','confirmed',Password::default()],
            'password_confirmation'=>['required','string'],
        ];
    }
}
