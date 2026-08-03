<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\JobContractService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessArea(User::ROLE_CONNECTED_REALMS) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'job' => ['required', 'string', Rule::in(JobContractService::jobKeys())],
        ];
    }

    public function job(): string
    {
        return (string) $this->validated('job');
    }
}
