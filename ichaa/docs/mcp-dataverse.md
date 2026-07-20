# Dataverse MCP Server

This repo now exposes a native Laravel MCP server on top of the Dataverse `/api/v1` authoring API.

## Start It Locally

Set an assistant token in your environment:

```env
DATAVERSE_MCP_TOKEN=your_sanctum_token_here
DATAVERSE_MCP_API_BASE=/api/v1
DATAVERSE_MCP_SOURCE=mcp
```

Start the local stdio server:

```bash
php artisan mcp:start dataverse
```

## Exposed Surfaces

- Local MCP handle: `dataverse`
- Web MCP endpoint: `/mcp/dataverse`
- Catalog resource: `dataverse://catalog`
- OAuth protected-resource metadata: `/.well-known/oauth-protected-resource/mcp/dataverse`
- OAuth authorization-server metadata: `/.well-known/oauth-authorization-server/mcp/dataverse`

## Tool Families

- Search: `search_dataverse`
- Read/list: `list_dataverse_records`, `get_dataverse_record`, `list_dataverse_trash`
- CRUD: `create_dataverse_record`, `update_dataverse_record`, `delete_dataverse_record`, `restore_dataverse_record`
- Media upload: `upload_dataverse_media`
- Media replace: `replace_dataverse_media_file`
- Custom verbs: `run_dataverse_action`
- History: `inspect_dataverse_revision`, `restore_dataverse_revision`
- Notion sync: `sync_dataverse_notion`

## Notes

- Non-create mutations should send the latest `base_revision_id`.
- The MCP tools proxy into the canonical `/api/v1` JSON API instead of bypassing domain rules.
- Use `dataverse://catalog` first when you need the exact resource slug or custom action name.
- `upload_dataverse_media` accepts plain base64 file content or a full data URL and creates a managed `media-references` upload record.
- `replace_dataverse_media_file` replaces the stored file for an existing media record and requires the current `base_revision_id`.
- The web MCP endpoint accepts both existing Sanctum service tokens and Passport OAuth bearer tokens.

## Remote OAuth Connect

Use this when connecting a remote MCP client like Notion AI through your public URL.

1. Expose the app publicly, for example `https://your-ngrok-domain.ngrok-free.dev`.
2. Point the client at `https://your-ngrok-domain.ngrok-free.dev/mcp/dataverse`.
3. When the client gets a `401`, it should discover OAuth from the `WWW-Authenticate` header and the two `/.well-known/...` endpoints above.
4. Finish the browser login + consent flow when the client opens it.
5. After that handshake, the client should receive a Passport OAuth access token and be able to call the MCP endpoint normally.

If a connect card is stuck on "needs connection", it usually means the browser-based login/consent step has not been completed yet.
https://reappear-absinthe-tinker.ngrok-free.dev/mcp/dataverse