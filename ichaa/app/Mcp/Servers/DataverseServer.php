<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\DataverseCatalogResource;
use App\Mcp\Tools\CreateDataverseRecordTool;
use App\Mcp\Tools\DeleteDataverseRecordTool;
use App\Mcp\Tools\GetDataverseRecordTool;
use App\Mcp\Tools\InspectDataverseRevisionTool;
use App\Mcp\Tools\ListDataverseRecordsTool;
use App\Mcp\Tools\ListDataverseTrashTool;
use App\Mcp\Tools\ReplaceDataverseMediaFileTool;
use App\Mcp\Tools\RestoreDataverseRecordTool;
use App\Mcp\Tools\RestoreDataverseRevisionTool;
use App\Mcp\Tools\RunDataverseActionTool;
use App\Mcp\Tools\SearchDataverseTool;
use App\Mcp\Tools\SyncDataverseNotionTool;
use App\Mcp\Tools\UpdateDataverseRecordTool;
use App\Mcp\Tools\UploadDataverseMediaTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Dataverse Authoring MCP')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Use this server to read, search, author, revise, restore, and sync Dataverse data through the canonical /api/v1 authoring API.

Start with the dataverse://catalog resource when you need the exact resource slug, include names, or custom action name.
For every non-create mutation, provide the latest base_revision_id to avoid stale-write conflicts.
Use the revision tools before restore operations when you need to inspect or compare prior state.
TEXT)]
class DataverseServer extends Server
{
    protected array $tools = [
        SearchDataverseTool::class,
        ListDataverseRecordsTool::class,
        GetDataverseRecordTool::class,
        CreateDataverseRecordTool::class,
        UploadDataverseMediaTool::class,
        ReplaceDataverseMediaFileTool::class,
        UpdateDataverseRecordTool::class,
        DeleteDataverseRecordTool::class,
        RestoreDataverseRecordTool::class,
        RunDataverseActionTool::class,
        InspectDataverseRevisionTool::class,
        RestoreDataverseRevisionTool::class,
        ListDataverseTrashTool::class,
        SyncDataverseNotionTool::class,
    ];

    protected array $resources = [
        DataverseCatalogResource::class,
    ];

    protected array $prompts = [];
}
