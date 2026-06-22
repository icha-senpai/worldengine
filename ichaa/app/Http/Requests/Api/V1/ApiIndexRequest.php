<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\DataverseRules;
use Illuminate\Foundation\Http\FormRequest;

class ApiIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return DataverseRules::index();
    }
}
