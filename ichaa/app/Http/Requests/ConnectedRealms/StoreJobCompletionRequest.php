<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\ItemPurposeService;
use App\Domain\ConnectedRealms\Services\JobContractService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

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
            'job' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $jobKey = (string) $value;

                    if (in_array($jobKey, JobContractService::jobKeys(), true)) {
                        return;
                    }

                    if (app(ItemPurposeService::class)->requisitionItemKey($jobKey) !== null) {
                        return;
                    }

                    $fail('The selected job is invalid.');
                },
            ],
        ];
    }

    public function job(): string
    {
        return (string) $this->validated('job');
    }
}
