package com.ecommerce.inventory.domain;

import java.util.List;

/** Business outcome of a reservation attempt; shortage is an outcome, not an error. */
public sealed interface ReserveResult {

  /** Every line was reserved, now or by an earlier call with the same id. */
  record Reserved() implements ReserveResult {}

  /** At least one line is short; nothing was reserved. */
  record InsufficientStock(List<Shortage> shortages) implements ReserveResult {

    public InsufficientStock {
      shortages = List.copyOf(shortages);
      if (shortages.isEmpty()) {
        throw new IllegalArgumentException("an insufficient-stock outcome lists its shortages");
      }
    }
  }

  /** The id was released (or tombstoned) before; nothing was reserved. */
  record AlreadyReleased() implements ReserveResult {}
}
