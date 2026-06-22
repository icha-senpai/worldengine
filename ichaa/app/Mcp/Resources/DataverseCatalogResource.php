<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\DataverseMcpCatalog;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('dataverse_catalog')]
#[Title('Dataverse Catalog')]
#[Uri('dataverse://catalog')]
#[MimeType('application/json')]
#[Description('Catalog of Dataverse API resources, custom actions, sync surfaces, and MCP usage notes.')]
class DataverseCatalogResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::json(DataverseMcpCatalog::catalog());
    }
}
