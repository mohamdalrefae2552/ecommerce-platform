-- Inventory schema (docs/05 section 2, ADR-0005). The CHECK on
-- stock_items.available is the last line of defence against overselling.

CREATE TABLE stock_items (
  product_id  varchar(26) PRIMARY KEY,
  available   bigint      NOT NULL CHECK (available >= 0),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

-- One row per caller-generated reservation_id. A RELEASED row without lines is
-- a tombstone written by ReleaseStock for an id it had never seen.
CREATE TABLE reservations (
  id             uuid        PRIMARY KEY,
  status         varchar(16) NOT NULL CHECK (status IN ('RESERVED', 'RELEASED')),
  created_at     timestamptz NOT NULL DEFAULT now(),
  released_at    timestamptz,
  release_reason varchar(48)
);

CREATE TABLE reservation_lines (
  reservation_id uuid        NOT NULL REFERENCES reservations (id),
  product_id     varchar(26) NOT NULL REFERENCES stock_items (product_id),
  quantity       int         NOT NULL CHECK (quantity > 0),
  PRIMARY KEY (reservation_id, product_id)
);

-- Administrative restock / write-off; the id is the caller's idempotency key.
CREATE TABLE stock_adjustments (
  id         uuid         PRIMARY KEY,
  product_id varchar(26)  NOT NULL REFERENCES stock_items (product_id),
  delta      bigint       NOT NULL CHECK (delta <> 0),
  note       varchar(200),
  created_at timestamptz  NOT NULL DEFAULT now()
);
