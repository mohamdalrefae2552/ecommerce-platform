package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import com.ecommerce.inventory.domain.Shortage;
import java.util.List;
import java.util.UUID;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

/** One short line rejects the whole order and changes nothing (docs/05 row 9). */
class ReserveAllOrNothingIT extends IntegrationTest {

  @Autowired private ReservationService reservations;

  @Test
  void anyShortLineReservesNothingAndReportsEveryShortage() {
    givenStock(productId(1), 5);
    givenStock(productId(2), 1);
    UUID id = UUID.randomUUID();

    ReserveResult result =
        reservations.reserve(
            id,
            List.of(
                new ReservationLine(productId(3), 1),
                new ReservationLine(productId(1), 2),
                new ReservationLine(productId(2), 2)));

    assertThat(result)
        .isEqualTo(
            new ReserveResult.InsufficientStock(
                List.of(new Shortage(productId(2), 2, 1), new Shortage(productId(3), 1, 0))));
    assertThat(availableOf(productId(1))).isEqualTo(5);
    assertThat(availableOf(productId(2))).isEqualTo(1);
    assertThat(
            jdbc.sql("SELECT count(*) FROM reservations WHERE id = :id")
                .param("id", id)
                .query(Integer.class)
                .single())
        .isZero();
  }

  @Test
  void aSufficientOrderReservesEveryLine() {
    givenStock(productId(1), 5);
    givenStock(productId(2), 2);

    ReserveResult result =
        reservations.reserve(
            UUID.randomUUID(),
            List.of(new ReservationLine(productId(2), 2), new ReservationLine(productId(1), 5)));

    assertThat(result).isEqualTo(new ReserveResult.Reserved());
    assertThat(availableOf(productId(1))).isZero();
    assertThat(availableOf(productId(2))).isZero();
  }
}
