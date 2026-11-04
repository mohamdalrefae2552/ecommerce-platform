package com.ecommerce.inventory.domain;

/** Why a reservation is released; stored for the audit trail only, it changes no behaviour. */
public enum ReleaseReason {
  /** Saving the order failed after the reservation succeeded. */
  ORDER_PERSISTENCE_FAILED,
  /** The ReserveStock outcome is unknown to the caller (timeout or unavailable). */
  RESERVE_OUTCOME_UNKNOWN,
  /** An administrator cancelled the order. */
  ORDER_CANCELLED,
  /** The caller's reconciler found an orphaned reservation. */
  RECONCILIATION
}
