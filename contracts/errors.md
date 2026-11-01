# Error catalog (RFC 9457 Problem Details)

Every error response of the REST API — including 404 for unknown routes, 405
and 500 — is `Content-Type: application/problem+json` with this shape
(schema `Problem` in `openapi/openapi.yaml`):

```json
{
  "type": "https://ecommerce.local/problems/insufficient-stock",
  "title": "Insufficient stock",
  "status": 409,
  "detail": "الكمية المتوفرة غير كافية لبعض المنتجات.",
  "instance": "/api/v1/orders",
  "code": "INSUFFICIENT_STOCK",
  "trace_id": "01JC3Z9A1B2C3D4E5F6G7H8J9K"
}
```

| Field | Rule |
|---|---|
| `type` | `https://ecommerce.local/problems/<code in kebab-case>` |
| `title` | Short English summary, constant per code |
| `status` | Same as the HTTP status |
| `detail` | Arabic, for the end user; never stack traces, SQL or file paths |
| `instance` | The request path |
| `code` | Stable, machine-readable; clients switch on this, never on `detail` |
| `trace_id` | Same value as the `X-Request-Id` response header |

Code-specific extensions: `errors` (422 `VALIDATION_FAILED`),
`retry_after` (429, and 503 from requirement 2), `shortages` (409
`INSUFFICIENT_STOCK`, requirement 2).

## Catalog

| HTTP | `code` | `type` suffix | When | Extra |
|---|---|---|---|---|
| 400 | `MALFORMED_REQUEST` | `malformed-request` | Body is not valid JSON; `Idempotency-Key` is not a UUID | — |
| 401 | `UNAUTHENTICATED` | `unauthenticated` | No token, bad signature, wrong `alg`/`iss`/`aud`, unknown `kid`, disabled user | `WWW-Authenticate: Bearer` |
| 401 | `TOKEN_EXPIRED` | `token-expired` | Access token expired (client should refresh) | `WWW-Authenticate: Bearer` |
| 401 | `INVALID_CREDENTIALS` | `invalid-credentials` | Wrong email **or** password (one message for both) | — |
| 401 | `REFRESH_TOKEN_INVALID` | `refresh-token-invalid` | Refresh cookie missing, expired, revoked or re-used (re-use revokes the family) | — |
| 403 | `FORBIDDEN` | `forbidden` | Role may not perform the operation (e.g. customer changes order status); refresh CSRF guard failed | — |
| 404 | `NOT_FOUND` | `not-found` | Resource or route does not exist, **or** the caller may not see it (D-02) | — |
| 405 | `METHOD_NOT_ALLOWED` | `method-not-allowed` | HTTP method not supported on the route | `Allow` |
| 409 | `INSUFFICIENT_STOCK` | `insufficient-stock` | Inventory rejected the reservation (R2-E3) | `shortages` |
| 409 | `ORDER_INVALID_TRANSITION` | `order-invalid-transition` | Status transition not allowed (e.g. `delivered → pending`) | — |
| 413 | `PAYLOAD_TOO_LARGE` | `payload-too-large` | Body above 3 MB (answered by `edge`) | — |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | `unsupported-media-type` | `Content-Type` not accepted by the operation | — |
| 422 | `VALIDATION_FAILED` | `validation-failed` | Input validation failed (incl. image above 2 MB, out-of-range pagination) | `errors` |
| 422 | `IDEMPOTENCY_KEY_REUSED` | `idempotency-key-reused` | Same `Idempotency-Key` with a different body | — |
| 429 | `RATE_LIMITED` | `rate-limited` | A rate limiter tripped | `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `retry_after` |
| 500 | `INTERNAL_ERROR` | `internal-error` | Anything unexpected | — |
| 503 | `INVENTORY_UNAVAILABLE` | `inventory-unavailable` | Inventory down (gRPC `UNAVAILABLE`/`ABORTED`/`RESOURCE_EXHAUSTED`) | `Retry-After: 5`, `retry_after` |
| 503 | `INVENTORY_TIMEOUT` | `inventory-timeout` | Inventory deadline exceeded | `Retry-After: 5`, `retry_after` |

The 409 `INSUFFICIENT_STOCK` and 503 codes are attached to operations in the
requirement-2 contract update (P2-LEAD-01).
