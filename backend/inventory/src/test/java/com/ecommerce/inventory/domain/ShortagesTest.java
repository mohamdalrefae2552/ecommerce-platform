package com.ecommerce.inventory.domain;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.List;
import java.util.Map;
import org.junit.jupiter.api.Test;

class ShortagesTest {

  @Test
  void reportsEveryShortLineOrderedByProductIdAndTreatsUnknownProductsAsZero() {
    List<ReservationLine> lines =
        List.of(
            new ReservationLine("C", 1),
            new ReservationLine("A", 5),
            new ReservationLine("B", 2),
            new ReservationLine("D", 3));
    Map<String, Long> available = Map.of("A", 4L, "B", 2L, "D", 10L);

    assertThat(Shortages.of(lines, available))
        .containsExactly(new Shortage("A", 5, 4), new Shortage("C", 1, 0));
  }

  @Test
  void isEmptyWhenEveryLineCanBeServedExactly() {
    assertThat(Shortages.of(List.of(new ReservationLine("A", 3)), Map.of("A", 3L))).isEmpty();
  }
}
