package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;

import com.ecommerce.inventory.ConcurrentCalls;
import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import com.ecommerce.inventory.persistence.SqlStates;
import java.util.ArrayList;
import java.util.List;
import java.util.Optional;
import java.util.UUID;
import java.util.concurrent.Callable;
import org.junit.jupiter.api.RepeatedTest;
import org.springframework.beans.factory.annotation.Autowired;

/**
 * Orders listing the same products in opposite orders, (A,B) and (B,A), at the same time: rows are
 * always locked in product_id order, so no transaction deadlocks (docs/05 row 2).
 *
 * <p>PostgreSQL reports a deadlock after {@code deadlock_timeout} (1 s), before the 1.8 s statement
 * timeout, so a deadlock would surface as {@code deadlock_detected}. A caller that only queued too
 * long on the two hot rows in this slow test environment is not a deadlock and is tolerated; the
 * stock must still be consistent with the reservations that succeeded.
 */
class DeadlockFreedomIT extends IntegrationTest {

  /** As many concurrent callers as the main application can produce (PHP-FPM max children). */
  private static final int CALLERS = 16;

  private static final int INITIAL_STOCK = 100;

  @Autowired private ReservationService reservations;

  @RepeatedTest(3)
  void opposingProductOrdersNeverDeadlock() throws Exception {
    String a = productId(1);
    String b = productId(2);
    givenStock(a, INITIAL_STOCK);
    givenStock(b, INITIAL_STOCK);
    List<Callable<Optional<Throwable>>> calls = new ArrayList<>();
    for (int i = 0; i < CALLERS; i++) {
      String first = i % 2 == 0 ? a : b;
      String second = i % 2 == 0 ? b : a;
      List<ReservationLine> lines =
          List.of(new ReservationLine(first, 1), new ReservationLine(second, 1));
      calls.add(
          () -> {
            try {
              assertThat(reservations.reserve(UUID.randomUUID(), lines))
                  .isEqualTo(new ReserveResult.Reserved());
              return Optional.empty();
            } catch (RuntimeException e) {
              return Optional.of(e);
            }
          });
    }

    List<Optional<Throwable>> failures = ConcurrentCalls.runSimultaneously(calls);

    List<String> sqlStates =
        failures.stream().flatMap(Optional::stream).flatMap(e -> SqlStates.of(e).stream()).toList();
    assertThat(sqlStates).doesNotContain("40P01");
    long reserved = failures.stream().filter(Optional::isEmpty).count();
    assertThat(reserved).isPositive();
    assertThat(availableOf(a)).isEqualTo(INITIAL_STOCK - reserved);
    assertThat(availableOf(b)).isEqualTo(INITIAL_STOCK - reserved);
  }
}
