package com.ecommerce.inventory.domain;

import java.util.Comparator;
import java.util.List;
import java.util.Map;

/** Shortage computation of a reservation (docs/05 section 2, step 4). */
public final class Shortages {

  private Shortages() {}

  /**
   * Every line whose quantity exceeds what is available, ordered by product id. Products without a
   * stock row count as zero units. Empty when the whole reservation can be served.
   */
  public static List<Shortage> of(List<ReservationLine> lines, Map<String, Long> available) {
    return lines.stream()
        .filter(line -> availableFor(line, available) < line.quantity())
        .map(line -> new Shortage(line.productId(), line.quantity(), availableFor(line, available)))
        .sorted(Comparator.comparing(Shortage::productId))
        .toList();
  }

  private static long availableFor(ReservationLine line, Map<String, Long> available) {
    return available.getOrDefault(line.productId(), 0L);
  }
}
