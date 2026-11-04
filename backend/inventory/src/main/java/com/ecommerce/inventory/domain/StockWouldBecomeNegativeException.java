package com.ecommerce.inventory.domain;

/**
 * An administrative adjustment would take the available quantity below zero. A call error of the
 * administrator, not a business outcome; nothing is changed.
 */
public final class StockWouldBecomeNegativeException extends RuntimeException {

  private static final long serialVersionUID = 1L;

  public StockWouldBecomeNegativeException(String productId, Throwable cause) {
    super("Adjustment would make the stock of " + productId + " negative", cause);
  }
}
