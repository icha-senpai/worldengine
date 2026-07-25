<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_bitcraft_recipe')]
#[Description('Search BitCraft craftable item/cargo targets and load the cascading recipe tree for a selected target.')]
class GetBitcraftRecipeTool extends DataverseTool
{
    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'itemId' => ['nullable', 'integer', 'min:1'],
            'itemKind' => ['nullable', Rule::in(['item', 'cargo'])],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999999'],
        ]);

        return $this->response(
            $this->gateway()->send(
                'GET',
                'bitcraft/crafting',
                $this->bitcraftQueryArray($request, ['q', 'itemKind'], ['itemId', 'quantity']),
            )
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Optional recipe target search, such as Simple Timber or Pickaxe.'),
            'itemId' => $schema->integer()->description('Optional selected item/cargo id. When supplied, detail and recipeTree are returned.'),
            'itemKind' => $schema->string()->enum(['item', 'cargo'])->description('Kind for itemId. Defaults to item.'),
            'quantity' => $schema->integer()->description('Desired output quantity used to scale tree batch quantities.'),
        ];
    }
}
