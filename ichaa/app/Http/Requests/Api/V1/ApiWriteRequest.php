<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\DataverseRules;
use Illuminate\Foundation\Http\FormRequest;

class ApiWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        $operation = $this->isMethod('post') ? 'store' : 'update';

        return array_merge(
            DataverseRules::api($resource, $operation),
            DataverseRules::metaRules($this->isMethod('patch')),
        );
    }
}
