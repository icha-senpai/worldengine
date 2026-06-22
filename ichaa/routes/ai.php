<?php

use App\Mcp\Servers\DataverseServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('dataverse', DataverseServer::class);

Mcp::oauthRoutes();

Mcp::web('/mcp/dataverse', DataverseServer::class)
    ->middleware('auth:sanctum,api');
