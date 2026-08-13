# Supported actions

| Action | Default risk | Reversible | Notes |
|---|---:|---:|---|
| Create draft post/page | Low | Yes | Rollback moves the created item to Trash. |
| Create published/future/private content | High | Yes | Requires native publish permission. |
| Update post/page fields | Medium/High | Yes | Status/date changes are high risk. |
| Trash post/page | High | Yes | Targeted post snapshot. |
| Supported builder metadata | High | Yes when snapshot fits | Raw builder payload never enters AI context. |
| Yoast/Rank Math metadata | Medium | Yes | Supported keys only. |
| Allowlisted core setting | Medium | Yes | No URLs, credentials, roles, plugin lists, or arbitrary options. |
| Activate/deactivate local plugin | High | Yes | Site Agent cannot deactivate itself. Network-wide actions excluded. |
| Delete expired transients | Medium | No | Deletion is intentionally non-reversible. |
| Rollback ledger entry | High | Produces a new entry | Conflict check and native target permission required. |

A successful action is not described as rollbackable until the ledger confirms a supported snapshot.
