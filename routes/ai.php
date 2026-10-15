<?php

use App\Http\Middleware\McpAuth;
use App\Mcp\Servers\MycsServer;
use Laravel\Mcp\Facades\Mcp;

/*
| MyCS's MCP server (docs/mcp.md), loaded by laravel/mcp. Staff's only: an admin API key with «Use the MCP Server»
| (McpAuth), never the customers' Passport tokens. No OAuth yet (the claude.ai connector needs a staff OAuth guard:
| docs/mcp.md).
*/
Mcp::web('/mcp', MycsServer::class)->middleware(McpAuth::class);
