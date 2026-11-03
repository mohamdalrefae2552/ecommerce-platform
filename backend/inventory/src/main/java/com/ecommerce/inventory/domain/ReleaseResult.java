package com.ecommerce.inventory.domain;

/** Outcome of a release; every repeat of a release is safe. */
public enum ReleaseResult {
  /** The reservation was active and its quantities were returned now. */
  RELEASED,
  /** The reservation had already been released; nothing changed. */
  ALREADY_RELEASED,
  /** The id was unknown; a tombstone now blocks any late reservation with it. */
  NOT_FOUND_TOMBSTONED
}
