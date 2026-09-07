# Slate AI Gateway

`slate-mcp` exposes a **scoped, revocable, tenant-specific** MCP-compatible JSON-RPC endpoint for approved AI clients.

## Install and connect

Install the plugin ZIP through Slate’s plugin manager, activate it, then open **AI Access**. Create one token for each AI client, using the least privileged scopes. The endpoint is:

```text
https://your-slate-domain.example/slate-mcp/mcp
```

Use `Authorization: Bearer <generated-token>`. The endpoint implements `initialize`, `tools/list`, and `tools/call` over HTTP POST.

## Deliberate scope boundary

The release exposes only React Site Bridge operations: list and inspect sites, read published manifests, update route documents/settings, and publish a revision. It does **not** expose generic code execution, filesystem writes, raw SQL, user administration, payments, or destructive site deletion. Those boundaries are intentional and should remain unless a separately reviewed tool is added.

## Available scopes

| Scope | Permitted capability |
|---|---|
| `react-sites.read` | List sites; inspect route documents and media mappings; read published manifests. |
| `react-sites.write` | Create or update route documents and frontend origins. Draft changes remain unpublished. |
| `react-sites.publish` | Publish a new immutable manifest revision. |

The access-token manager shows every issued token, its scopes, last use, expiry, and revocation status. Plaintext credentials appear once only.
