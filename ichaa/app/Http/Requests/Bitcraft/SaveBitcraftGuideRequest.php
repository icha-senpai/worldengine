<?php

namespace App\Http\Requests\Bitcraft;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBitcraftGuideRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:100'],
            'content' => ['required', 'array:type,content'],
            'content.type' => ['required', Rule::in(['doc'])],
            'content.content' => ['required', 'array', 'min:1'],
            'content.content.*.type' => ['required', 'string'],
            'is_published' => ['required', 'boolean'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }

            $this->validateItemCards($this->input('content'), $validator);

            if (! $this->hasContent($this->input('content'))) {
                $validator->errors()->add('content', 'Write some guide content before saving.');
            }
        }];
    }

    private function hasContent(array $node): bool
    {
        if (($node['type'] ?? null) === 'text' && trim((string) ($node['text'] ?? '')) !== '') {
            return true;
        }

        if (($node['type'] ?? null) === 'image' && ! empty($node['attrs']['src'])) {
            return true;
        }

        if (in_array($node['type'] ?? null, ['bitcraftItem', 'bitcraftActivity'], true)) {
            return true;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child) && $this->hasContent($child)) {
                return true;
            }
        }

        return false;
    }

    private function validateItemCards(array $node, Validator $validator, string $path = 'content'): void
    {
        if (in_array($node['type'] ?? null, ['bitcraftItem', 'bitcraftActivity'], true)) {
            $attributes = $node['attrs'] ?? null;
            if (! is_array($attributes)) {
                $validator->errors()->add($path.'.attrs', 'Choose an item for this card.');

                return;
            }

            $rules = ($node['type'] === 'bitcraftItem') ? [
                'id' => ['required', 'integer', 'min:1'],
                'kind' => ['required', Rule::in(['item', 'cargo'])],
                'name' => ['required', 'string', 'max:255'],
                'category' => ['nullable', 'string', 'max:255'],
                'tier' => ['nullable', 'integer', 'min:-1', 'max:10'],
                'rarity' => ['nullable', 'string', 'max:50'],
                'iconAssetName' => ['nullable', 'string', 'max:255'],
            ] : [
                'activity' => ['required', Rule::in(['crafting', 'gathering'])],
                'recipeId' => ['required', 'integer', 'min:1'],
                'itemId' => ['required_if:activity,crafting', 'nullable', 'integer', 'min:1'],
                'kind' => ['required', Rule::in(['item', 'cargo'])],
                'name' => ['required', 'string', 'max:255'],
                'settings' => ['required', 'array:quantity,mode,power,gatheringSpeed,skillSpeed,minutes,critChance,critMultiplier,market,region'],
                'settings.quantity' => ['sometimes', 'integer', 'min:1', 'max:999999'],
                'settings.mode' => ['sometimes', Rule::in(['single', 'sustained'])],
                'settings.power' => ['sometimes', 'numeric', 'min:1', 'max:9999'],
                'settings.gatheringSpeed' => ['sometimes', 'numeric', 'min:0', 'max:999'],
                'settings.skillSpeed' => ['sometimes', 'numeric', 'min:0', 'max:999'],
                'settings.minutes' => ['sometimes', 'numeric', 'min:0.1', 'max:1440'],
                'settings.critChance' => ['sometimes', 'numeric', 'min:0', 'max:100'],
                'settings.critMultiplier' => ['sometimes', 'numeric', 'min:1', 'max:100'],
                'settings.market' => ['sometimes', 'boolean'],
                'settings.region' => ['nullable', 'string', 'max:120'],
            ];
            $rules['align'] = ['sometimes', Rule::in(['left', 'center', 'right'])];
            $rules['width'] = ['nullable', 'integer', 'min:25', 'max:100'];
            $rules['wrap'] = ['sometimes', 'boolean:strict'];
            $card = ValidatorFacade::make($attributes, $rules);

            foreach ($card->errors()->messages() as $key => $messages) {
                $validator->errors()->add($path.'.attrs.'.$key, $messages[0]);
            }
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $index => $child) {
            if (is_array($child)) {
                $this->validateItemCards($child, $validator, $path.'.content.'.$index);
            }
        }
    }
}
