package com.ecommerce.inventory.domain;

/** Stored state of a reservation id. */
public enum ReservationStatus {
  /** Quantities are held for the order. */
  RESERVED,
  /** Quantities were returned, or the id was tombstoned before any reservation. */
  RELEASED
}
