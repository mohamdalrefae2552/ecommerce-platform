package com.ecommerce.inventory.domain;

/**
 * One order line to reserve.
 *
 * @param productId product ULID
 * @param quantity units to reserve, positive
 */
public record ReservationLine(String productId, int quantity) {

  public ReservationLine {
    if (quantity <= 0) {
      throw new IllegalArgumentException("quantity must be positive");
    }
  }
}
