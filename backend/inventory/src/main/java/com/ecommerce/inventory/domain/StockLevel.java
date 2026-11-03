package com.ecommerce.inventory.domain;

/**
 * Current availability of one product.
 *
 * @param productId product ULID
 * @param available units that can still be reserved (reserved units excluded), never negative
 * @param tracked false when the inventory has never seen the product (no AdjustStock yet)
 */
public record StockLevel(String productId, long available, boolean tracked) {

  public StockLevel {
    if (available < 0) {
      throw new IllegalArgumentException("available must not be negative");
    }
    if (!tracked && available != 0) {
      throw new IllegalArgumentException("an untracked product has no stock");
    }
  }

  public static StockLevel tracked(String productId, long available) {
    return new StockLevel(productId, available, true);
  }

  public static StockLevel untracked(String productId) {
    return new StockLevel(productId, 0, false);
  }
}
