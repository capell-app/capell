<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Override;

final class RunInstallStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'install_id' => ['required', 'uuid'],
            'step' => ['required', 'string'],
        ];
    }

    #[Override]
    protected function failedValidation(Validator $validator): never
    {
        $exception = new ValidationException($validator);

        throw new HttpResponseException(response()->json([
            'message' => $exception->getMessage(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
