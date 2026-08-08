# Bitjita API Usage

Last live spot-check: 2026-08-08.

This document lists every Bitjita endpoint currently used by the Bitcraft tools,
the response shape Dataverse expects, and the traps already found while building
the market UI.

Source of truth in this repo:

- `app/Domain/Bitcraft/Services/BitjitaClient.php`
- `app/Http/Controllers/Bitcraft/BitcraftToolController.php`
- `app/Http/Controllers/Bitcraft/BitcraftActivityController.php`
- `app/Http/Controllers/Bitcraft/BitcraftInventoryTrackerController.php`
- `resources/js/Pages/Bitcraft/bitjitaAssets.js`

## Client Rules

Base URL comes from `services.bitjita.base_url`, defaulting to
`https://bitjita.com`.

Every request sends:

```http
Accept: application/json
x-app-identifier: Dataverse Bitcraft Tools
```

Optional headers:

```http
x-bitjita-identity: <BITJITA_IDENTITY>
Authorization: Bearer <BITJITA_TOKEN>
```

Query cleanup:

- `null`, empty string, and `false` are omitted.
- `true` is sent as the string `true`.
- This matters for Bitjita market flags: use `hasBuyOrders=true`, not
  `hasBuyOrders=1`.

## Critical Market Notes

Do not use `/api/market?regionId=...` as a region-filtered item catalog.

Live check on 2026-08-08:

```text
/api/market?hasBuyOrders=true                -> 1214 items
/api/market?hasBuyOrders=true&regionId=8     -> 1214 items, same first IDs
/api/market?hasBuyOrders=true&regionId=9     -> 1214 items, same first IDs
/api/market?q=Astralite                      -> 377 items
/api/market?q=Astralite&regionId=8           -> 377 items, same first IDs
```

`regionId` is real on other endpoints, but it is not honored by the global
market catalog endpoint. For regional market searches, including plain
`region + q` searches with no order checkbox selected, use:

1. `/api/claims?regionId=...`
2. `/api/claims/{claimEntityId}/buildings`
3. `/api/claims/{claimEntityId}/market/listings`

Then build the item cards from those listings.

`claimEntityId` is real on `/api/market`.

Live check on 2026-08-08:

```text
/api/market?hasOrders=true
  -> 2712 items
/api/market?hasOrders=true&claimEntityId=864691128472806646
  -> 790 items
/api/market?hasOrders=true&claimEntityId=864691128472806646&regionId=8
  -> 790 items, same first IDs
/api/market?hasOrders=true&claimEntityId=1
  -> 0 items
```

## Endpoint Map

### `GET /api/regions`

Used by:

- Market Finder
- Barter Stall Finder
- Market order-book popup

Query params: none.

Cache: `BITJITA_REGIONS_CACHE_SECONDS`, default 86400.

Live response shape:

```json
[
  {
    "regionId": 8,
    "regionName": "Solmere"
  }
]
```

Consumed fields:

- `regionId`
- `regionName`

### `GET /api/market`

Used by:

- Global Market Finder searches
- Claim-scoped market searches
- Global market order filters

Query params we send:

- `q`
- `category`
- `claimEntityId`
- `hasOrders=true`
- `hasSellOrders=true`
- `hasBuyOrders=true`

Query params to avoid:

- `regionId` does not produce a real regional catalog filter.
- Do not send `regionId` to `/api/market` even alongside `q` or
  `claimEntityId`; it is ignored in both cases.

Cache: `BITJITA_MARKET_CACHE_SECONDS`, default 60.

Live response shape:

```json
{
  "data": {
    "items": [
      {
        "id": "1980091378",
        "name": "Advanced Cart Wheel Set",
        "tag": "Cart Part",
        "tier": 4,
        "rarity": 1,
        "rarityStr": "Common",
        "iconAssetName": "GeneratedIcons/...",
        "hasOrders": true,
        "hasSellOrders": false,
        "hasBuyOrders": true,
        "sellOrders": 0,
        "buyOrders": 1,
        "totalOrders": 1
      }
    ],
    "categories": [],
    "metrics": {}
  }
}
```

Consumed fields:

- `data.items`
- `data.categories`
- `data.metrics`
- Item identity: `id`, `name`, `tag`, `tier`, `rarity`, `rarityStr`,
  `iconAssetName`
- Order flags/counts: `hasOrders`, `hasSellOrders`, `hasBuyOrders`,
  `sellOrders`, `buyOrders`, `totalOrders`
- Optional stats/count aliases if present: `stats.lowestSell`,
  `stats.highestBuy`, `stats.lowestSellPrice`, `stats.highestBuyPrice`,
  `sellOrderCount`, `buyOrderCount`, `sellOrderQuantity`,
  `buyOrderQuantity`

### `GET /api/market/{itemKind}/{itemId}`

`itemKind` is `item` or `cargo`.

Used by:

- Market order-book popup
- Selected Market Finder item details
- Package order display

Query params we send:

- `claimEntityId`
- `regionId`

Unlike `/api/market`, `regionId` is useful here because Dataverse also filters
the returned orders by `regionId`/`regionName` after receiving the order book.

Cache: `BITJITA_MARKET_ORDERS_CACHE_SECONDS`, default 30.

Live response shape:

```json
{
  "item": {
    "id": "1421716234",
    "name": "Astralite Pickaxe",
    "tag": "Miner Tool",
    "tier": 5,
    "rarityStr": "Rare",
    "iconAssetName": "GeneratedIcons/..."
  },
  "sellOrders": [
    {
      "entityId": "order-id",
      "ownerUsername": "Seller",
      "claimEntityId": "claim-id",
      "claimName": "Market Claim",
      "priceThreshold": "1200",
      "quantity": "4",
      "regionId": 8,
      "regionName": "Solmere"
    }
  ],
  "buyOrders": [],
  "packageInfo": {
    "cargoId": "150006",
    "cargoName": "Astralite Pickaxe Package",
    "cargoIconAssetName": "GeneratedIcons/...",
    "itemId": "1421716234",
    "itemName": "Astralite Pickaxe",
    "itemIconAssetName": "GeneratedIcons/...",
    "ratio": 100
  },
  "packageSellOrders": [],
  "packageBuyOrders": [],
  "stats": {
    "lowestSell": 1200,
    "highestBuy": 900
  }
}
```

Consumed fields:

- `item.id`, `item.name`, `item.tag`, `item.tier`, `item.rarityStr`,
  `item.iconAssetName`
- `sellOrders`, `buyOrders`, `packageSellOrders`, `packageBuyOrders`
- Order fields: `entityId`, `ownerUsername`, `claimEntityId`, `claimName`,
  `priceThreshold` or `price`, `quantity`, `regionId`, `regionName`,
  `updatedAt` or `timestamp`
- `packageInfo.cargoId`, `cargoName`, `cargoIconAssetName`, `itemId`,
  `itemName`, `itemIconAssetName`, `ratio`
- `stats`

Dataverse recomputes order-book stats from the filtered orders, including:

- `lowestSell`
- `highestBuy`
- `lowestBuy`
- `sellOrderCount`
- `buyOrderCount`
- high/low buy quantity and line totals

### `GET /api/claims`

Used by:

- Claim search
- Region and empire market filtering
- Regional market listing search
- Barter claim filtering

Query params we send:

- `q`
- `page`
- `limit=100`
- `sort=name`
- `order=asc`
- `regionId`

Cache: `BITJITA_CLAIMS_CACHE_SECONDS`, default 300.

Live response shape:

```json
{
  "claims": [
    {
      "entityId": "576460753750666168",
      "name": "Claim Name",
      "regionId": 8,
      "regionName": "Solmere",
      "empireEntityId": "1807",
      "empireName": "Empire Name",
      "locationX": 123,
      "locationZ": 456,
      "tier": 5,
      "treasury": "0"
    }
  ],
  "count": 1
}
```

Consumed fields:

- `claims`
- `count`
- Claim fields: `entityId`, `name`, `regionId`, `regionName`,
  `empireEntityId`, `empireName`, `locationX`, `locationZ`, `tier`,
  `treasury`, `supplies`, `numTiles`, `ownerPlayerEntityId`

The client paginates until `ceil(count / 100)` pages are loaded.

### `GET /api/claims/{claimEntityId}`

Used by:

- Fallback claim lookup when a claim ID is directly selected.

Cache: `BITJITA_CLAIM_DETAILS_CACHE_SECONDS`, default 300.

Live response shape:

```json
{
  "claim": {
    "entityId": "576460753750666168",
    "name": "Claim Name",
    "regionId": 8,
    "regionName": "Solmere",
    "empireEntityId": "1807",
    "empireName": "Empire Name",
    "ownerPlayerEntityId": "player-id",
    "ownerPlayerUsername": "Owner",
    "locationX": 123,
    "locationZ": 456,
    "tier": 5
  }
}
```

Consumed fields:

- `claim`
- Same normalized claim fields as `/api/claims`.

### `GET /api/claims/{claimEntityId}/buildings`

Used by:

- Market claim filtering, to keep only claims with market buildings.
- Barter/claim UI building summaries.

Query params: none.

Cache: `BITJITA_CLAIM_BUILDINGS_CACHE_SECONDS`, default 300.

Live response shape:

```json
[
  {
    "entityId": "building-id",
    "buildingName": "Market",
    "buildingDescriptionId": 123,
    "iconAssetName": "GeneratedIcons/...",
    "functions": []
  }
]
```

Test/fake payloads may also use:

```json
{
  "buildings": [
    {
      "entityId": "building-id",
      "buildingName": "Market",
      "tradeOrders": 4
    }
  ]
}
```

Consumed fields:

- Root array or `buildings`
- `entityId`
- `buildingName`
- `buildingNickname`
- `buildingDescriptionId`
- `iconAssetName`
- `functions`
- `tradeOrders`

### `GET /api/claims/{claimEntityId}/market/listings`

Used by:

- Regional Market Finder cards
- Region plus `hasOrders` / `hasSellOrders` / `hasBuyOrders` filters

Query params we send:

- `page`
- `limit=200`
- `side=buy`
- `side=sell`
- `itemType`
- `itemId`

Cache: `BITJITA_CLAIM_MARKET_LISTINGS_CACHE_SECONDS`, default 30.

Live response shape:

```json
{
  "claim": {
    "entityId": "864691128472806646",
    "name": "Claim Name"
  },
  "listings": [
    {
      "entityId": "listing-id",
      "side": "buy",
      "ownerEntityId": "player-id",
      "ownerUsername": "Buyer",
      "claimEntityId": "864691128472806646",
      "itemId": "1980091378",
      "itemType": 0,
      "itemName": "Advanced Cart Wheel Set",
      "itemTag": "Cart Part",
      "itemTier": 4,
      "itemRarity": 1,
      "itemRarityStr": "Common",
      "iconAssetName": "GeneratedIcons/...",
      "price": "700",
      "quantity": "20",
      "regionId": 8,
      "regionName": "Solmere",
      "timestamp": "2026-08-08T00:00:00.000Z",
      "updatedAt": "2026-08-08T00:00:00.000Z"
    }
  ],
  "count": 215,
  "page": 1,
  "limit": 5,
  "totalPages": 43
}
```

Consumed fields:

- `claim.entityId`, `claim.name`
- `listings`
- `count`, `page`, `limit`, `totalPages`
- Listing fields: `entityId`, `side`, `ownerUsername`, `claimEntityId`,
  `itemId`, `itemType`, `itemName`, `itemTag`/`itemCategory`, `itemTier`,
  `itemRarity`, `itemRarityStr`, `iconAssetName`, `price`/`priceThreshold`,
  `quantity`, `regionId`, `regionName`, `timestamp`, `updatedAt`

The client paginates through `totalPages`.

### `GET /api/stalls`

Used by:

- Barter Stall Finder

Query params we send:

- `page`
- `limit=100`

Cache: `BITJITA_STALLS_CACHE_SECONDS`, default 300.

Live response shape:

```json
{
  "stalls": [
    {
      "entityId": "stall-id",
      "nickname": "Icha Cart",
      "ownerEntityId": "player-id",
      "ownerName": "Icha",
      "claimName": "Omashu",
      "regionId": 8,
      "regionName": "Solmere",
      "locationX": 123,
      "locationZ": 456,
      "marketModeEnabled": true,
      "orderCount": 1,
      "orders": [
        {
          "entityId": "order-id",
          "remainingStock": 3,
          "offerItems": [],
          "requiredItems": [],
          "offerCargo": [],
          "requiredCargo": []
        }
      ]
    }
  ],
  "page": 1,
  "limit": 100,
  "totalPages": 1,
  "totalStalls": 1,
  "totalOrders": 1
}
```

Consumed fields:

- `stalls`, `page`, `limit`, `totalPages`, `totalStalls`, `totalOrders`
- Stall fields: `entityId`, `nickname`, `ownerName`, `claimName`, `regionId`,
  `regionName`, `locationX`, `locationZ`, `marketModeEnabled`, `orderCount`,
  `orders`
- Order fields: `entityId`, `remainingStock`, `offerItems`, `requiredItems`,
  `offerCargo`, `requiredCargo`
- Item fields inside orders: `itemId`, `itemName`, `quantity`,
  `iconAssetName`
- Cargo fields inside orders use the same stack shape: `itemId`, `itemName`,
  `quantity`, `iconAssetName`

The client paginates through `totalPages`.

### `GET /api/empires`

Used by:

- Empire filter resolution.

Query params we send:

- `q`

Cache: `BITJITA_EMPIRES_CACHE_SECONDS`, default 600.

Live response shape:

```json
{
  "empires": [
    {
      "entityId": "1807",
      "name": "Empire Name",
      "leader": "Leader",
      "leaderEntityId": "player-id",
      "memberCount": 10,
      "numClaims": 3,
      "locationX": 123,
      "locationZ": 456
    }
  ],
  "count": 1,
  "totalClaims": 3,
  "totalMembers": 10
}
```

Consumed fields:

- `empires`
- `entityId`, `name`, `leader`, `leaderEntityId`, `memberCount`,
  `numClaims`, `locationX`, `locationZ`

### `GET /api/empires/{empireEntityId}/claims`

Used by:

- Empire-scoped claim search.

Cache: `BITJITA_EMPIRES_CACHE_SECONDS`, default 600.

Live response shape:

```json
{
  "claims": [],
  "count": 0
}
```

Consumed fields:

- `claims`
- `count`
- Claim fields are normalized the same as `/api/claims`.

### `GET /api/items`

Used by:

- Crafting search
- Inventory tracker item options

Query params we send:

- `q`

Cache: `BITJITA_ITEMS_CACHE_SECONDS`, default 3600.

Live response shape:

```json
{
  "items": [
    {
      "id": "1421716234",
      "name": "Astralite Pickaxe",
      "tag": "Miner Tool",
      "tier": 5,
      "rarity": 3,
      "rarityStr": "Rare",
      "iconAssetName": "GeneratedIcons/...",
      "volume": 1
    }
  ],
  "metrics": {}
}
```

Consumed fields:

- `items`
- `id`, `name`, `tag`, `tier`, `rarity`, `rarityStr`, `iconAssetName`,
  `volume`

### `GET /api/items/{itemId}`

Used by:

- Crafting detail
- Recipe tree expansion

Cache: `BITJITA_ITEMS_CACHE_SECONDS`, default 3600.

Live response shape:

```json
{
  "item": {
    "id": "1421716234",
    "name": "Astralite Pickaxe",
    "tag": "Miner Tool",
    "tier": 5,
    "rarityStr": "Rare",
    "iconAssetName": "GeneratedIcons/..."
  },
  "marketStats": {
    "lowestSellPrice": 1200,
    "highestBuyPrice": 900,
    "totalSellOrders": 1,
    "totalBuyOrders": 1
  },
  "toolStats": {},
  "foodStats": {},
  "equipmentStats": [],
  "relatedSkills": [],
  "craftingRecipes": [],
  "extractionRecipes": [],
  "recipesUsingItem": [],
  "itemListPossibilities": []
}
```

Consumed fields:

- `item`
- `marketStats`
- `toolStats`, `foodStats`, `equipmentStats`
- `relatedSkills`
- `craftingRecipes`, `extractionRecipes`, `recipesUsingItem`
- Recipe fields: `id`, `name`, `targetId`, `outputQuantity`,
  `craftedItems`, `craftedItemStacks`, `consumedItems`,
  `consumedItemStacks`, `levelRequirements`, `toolRequirements`,
  `buildingName`, `buildingIconAssetName`, `buildingRequirementTier`,
  `staminaRequirement`, `timeRequirement`, `actionsRequired`,
  `experiencePerProgress`, `isPassive`

### `GET /api/cargo`

Used by:

- Crafting search
- Inventory tracker item options

Query params we send:

- `q`

Cache: `BITJITA_ITEMS_CACHE_SECONDS`, default 3600.

Live response shape:

```json
{
  "cargos": [
    {
      "id": "260001",
      "name": "Sturdy Ingot",
      "tag": "Metal",
      "tier": 3,
      "rarityStr": "Common",
      "iconAssetName": "GeneratedIcons/..."
    }
  ],
  "count": 1
}
```

Consumed fields:

- `cargos`
- `id`, `name`, `tag`, `tier`, `rarity`, `rarityStr`, `iconAssetName`,
  `volume`

### `GET /api/cargo/{cargoId}`

Used by:

- Crafting detail
- Recipe tree expansion

Cache: `BITJITA_ITEMS_CACHE_SECONDS`, default 3600.

Live response shape:

```json
{
  "cargo": {
    "id": "260001",
    "name": "Sturdy Ingot",
    "tag": "Metal",
    "tier": 3,
    "rarityStr": "Common",
    "iconAssetName": "GeneratedIcons/..."
  },
  "marketStats": {},
  "relatedSkills": [],
  "craftingRecipes": [],
  "extractionRecipes": [],
  "recipesUsingItem": []
}
```

Consumed fields:

- `cargo`
- `marketStats`
- `relatedSkills`
- `craftingRecipes`, `extractionRecipes`, `recipesUsingItem`
- Recipe fields are consumed the same as `/api/items/{itemId}`.

### `GET /api/players`

Used by:

- Activity widget player resolution
- Inventory tracker player resolution

Query params we send:

- `q`

Cache: none.

Live response shape:

```json
{
  "players": [
    {
      "entityId": "1224979098725428189",
      "username": "icha",
      "signedIn": true,
      "lastLoginTimestamp": "2026-08-08T00:00:00.000Z",
      "timePlayed": 123,
      "timeSignedIn": 45
    }
  ],
  "total": 24
}
```

Consumed fields:

- `players`
- `total`
- `entityId`, `username`, `signedIn`, `lastLoginTimestamp`,
  `timePlayed`, `timeSignedIn`, `updatedAt`

### `GET /api/players/{playerEntityId}`

Used by:

- Activity widget skill/XP snapshot
- Inventory tracker player identity before inventory lookup

Path param:

- Must be a player entity ID.
- Do not pass username text here. Resolve username through `/api/players?q=...`
  first.

Cache: none.

Live response shape:

```json
{
  "player": {
    "entityId": "1224979098725428189",
    "username": "icha",
    "signedIn": true,
    "updatedAt": "2026-08-08T00:00:00.000Z",
    "skillMap": [],
    "experience": [
      {
        "skill_id": 5,
        "quantity": 12345
      }
    ]
  }
}
```

Consumed fields:

- `player.entityId`, `player.username`, `player.signedIn`,
  `player.updatedAt`
- `player.skillMap`
- `player.experience`
- Skill fields: `id`, `name`, `title`
- Experience fields: `skill_id` or `skillId`, `quantity`

### `GET /api/players/{playerEntityId}/inventories`

Used by:

- Inventory tracker snapshot

Query params we send:

- `q` is supported by the client method, but current controller usage fetches
  all inventories for the player.

Cache: none.

Expected response shape:

```json
{
  "availableRegions": [],
  "items": {
    "1421716234": {
      "name": "Astralite Pickaxe",
      "tag": "Miner Tool",
      "tier": 5,
      "rarityStr": "Rare",
      "iconAssetName": "GeneratedIcons/..."
    }
  },
  "cargos": {
    "260001": {
      "name": "Sturdy Ingot",
      "tag": "Metal",
      "tier": 3,
      "rarityStr": "Common",
      "iconAssetName": "GeneratedIcons/..."
    }
  },
  "inventories": [
    {
      "entityId": "inventory-id",
      "inventoryName": "Inventory",
      "buildingName": "Personal Cache",
      "claimEntityId": "claim-id",
      "claimName": "Claim Name",
      "regionId": 8,
      "pockets": [
        {
          "locked": false,
          "volume": 1,
          "contents": {
            "itemType": 0,
            "itemId": "1421716234",
            "quantity": 1
          }
        }
      ]
    }
  ]
}
```

Consumed fields:

- `availableRegions` is returned by Bitjita but not currently consumed.
- `items`, `cargos`
- `inventories`
- Inventory fields: `inventoryName`, `buildingName`, `pockets`, `inventory`
- Pocket fields: `contents.itemType` or `contents.item_type`,
  `contents.itemId` or `contents.item_id`, `contents.quantity`

### `GET /static/experience/levels.json`

Used by:

- Activity widget level progress calculations

Query params: none.

Cache: 86400.

Live response shape:

```json
[
  {
    "level": 1,
    "xp": 0
  }
]
```

Consumed fields:

- `level`
- `xp`

## Assets

Not a JSON API endpoint, but the frontend builds icon URLs from Bitjita asset
names.

Used by:

- Market Finder
- Crafting
- Recipe tree components

URL rule:

```text
https://bitjita.com/{GeneratedIcons/...}.webp
```

`bitjitaAssetUrl()` only accepts asset names that resolve under
`GeneratedIcons/`. It normalizes backslashes, strips prefixes before
`GeneratedIcons/`, appends `.webp` when no image extension exists, and rejects
paths with bracket/private-use characters.

## Regression Checklist

When touching Bitjita market code:

1. Verify the exact Bitjita endpoint live before trusting a query param.
2. Do not add `regionId` to `/api/market` expecting regional results.
3. For regional market item cards, aggregate claim market listings.
4. Send Bitjita booleans as `true`, not `1`.
5. Keep item IDs and cargo IDs separate; use `itemKind` to choose
   `/api/market/item/{id}` versus `/api/market/cargo/{id}`.
6. Run the focused market tests after changes:

```powershell
php artisan test --compact tests\Feature\Bitcraft\BitcraftToolTest.php tests\Feature\Bitcraft\BitcraftApiMcpToolTest.php
npm test -- --run resources\js\tests\BitcraftMarketPage.test.js resources\js\tests\BitcraftPopups.test.js
```
