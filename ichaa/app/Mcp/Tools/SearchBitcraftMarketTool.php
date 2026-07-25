<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('search_bitcraft_market')]
#[Description('Search BitCraft market items and optionally load an order book for a selected item or cargo target.')]
class SearchBitcraftMarketTool extends DataverseTool
{
    public function handle(Request $request): ResponseFactory
    {
        $request->validate($this->rules());

        return $this->response(
            $this->gateway()->send('GET', 'bitcraft/market', $this->query($request))
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Optional item, cargo, or text query.'),
            'category' => $schema->string()->description('Optional category/tag filter.'),
            'claimQ' => $schema->string()->description('Optional claim name search.'),
            'claimEntityId' => $schema->string()->description('Optional exact claim/market entity id.'),
            'empire' => $schema->string()->description('Optional empire name or id.'),
            'empireEntityId' => $schema->string()->description('Optional exact empire entity id.'),
            'region' => $schema->string()->description('Optional region name or id.'),
            'regionId' => $schema->string()->description('Optional exact region id.'),
            'itemId' => $schema->integer()->description('Optional item/cargo id. When supplied, the market order book is loaded.'),
            'itemKind' => $schema->string()->enum(['item', 'cargo'])->description('Kind for itemId.'),
            'side' => $schema->string()->enum(['sell', 'buy'])->description('Optional side filter.'),
            'hasOrders' => $schema->boolean()->description('Only include targets with any orders.'),
            'hasSellOrders' => $schema->boolean()->description('Only include targets with sell orders.'),
            'hasBuyOrders' => $schema->boolean()->description('Only include targets with buy orders.'),
        ];
    }

    private function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'claimQ' => ['nullable', 'string', 'max:120'],
            'claimEntityId' => ['nullable', 'regex:/^\d+$/'],
            'empire' => ['nullable', 'string', 'max:120'],
            'empireEntityId' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'regionId' => ['nullable', 'string', 'max:120'],
            'itemId' => ['nullable', 'integer', 'min:1'],
            'itemKind' => ['nullable', Rule::in(['item', 'cargo'])],
            'side' => ['nullable', Rule::in(['sell', 'buy'])],
            'hasOrders' => ['nullable', 'boolean'],
            'hasSellOrders' => ['nullable', 'boolean'],
            'hasBuyOrders' => ['nullable', 'boolean'],
        ];
    }

    private function query(Request $request): array
    {
        return $this->bitcraftQueryArray(
            $request,
            ['q', 'category', 'claimQ', 'claimEntityId', 'empire', 'empireEntityId', 'region', 'regionId', 'itemKind', 'side'],
            ['itemId'],
            ['hasOrders', 'hasSellOrders', 'hasBuyOrders'],
        );
    }
}
