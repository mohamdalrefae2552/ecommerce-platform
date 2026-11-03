package com.ecommerce.inventory.grpc;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.grpc.v1.ReservationItem;
import com.ecommerce.inventory.grpc.v1.ReserveOutcome;
import com.ecommerce.inventory.grpc.v1.ReserveStockRequest;
import com.ecommerce.inventory.grpc.v1.ReserveStockResponse;
import com.ecommerce.inventory.grpc.v1.Shortage;
import io.grpc.Status;
import io.grpc.StatusRuntimeException;
import java.util.List;
import java.util.Locale;
import java.util.UUID;
import org.junit.jupiter.api.Test;

class ReserveStockGrpcIT extends IntegrationTest {

  @Test
  void reservesAndEchoesTheReservationId() {
    givenStock(productId(1), 2);
    String id = UUID.randomUUID().toString();

    ReserveStockResponse response = inventory().reserveStock(request(id, item(productId(1), 2)));

    assertThat(response.getReservationId()).isEqualTo(id);
    assertThat(response.getOutcome()).isEqualTo(ReserveOutcome.RESERVE_OUTCOME_RESERVED);
    assertThat(response.getShortagesList()).isEmpty();
  }

  @Test
  void reportsInsufficientStockAsAnOkOutcomeWithShortages() {
    givenStock(productId(1), 1);

    ReserveStockResponse response =
        inventory().reserveStock(request(UUID.randomUUID().toString(), item(productId(1), 3)));

    assertThat(response.getOutcome()).isEqualTo(ReserveOutcome.RESERVE_OUTCOME_INSUFFICIENT_STOCK);
    assertThat(response.getShortagesList())
        .containsExactly(
            Shortage.newBuilder()
                .setProductId(productId(1))
                .setRequested(3)
                .setAvailable(1)
                .build());
  }

  @Test
  void rejectsRequestsThatBreakTheContract() {
    String id = UUID.randomUUID().toString();
    assertInvalid(request("not-a-uuid", item(productId(1), 1)), "reservation_id");
    assertInvalid(request(id.toUpperCase(Locale.ROOT), item(productId(1), 1)), "reservation_id");
    assertInvalid(request(id), "items must contain 1 to 100 entries");
    assertInvalid(request(id, item(productId(1), 0)), "items[0].quantity");
    assertInvalid(request(id, item(productId(1), 1001)), "items[0].quantity");
    assertInvalid(request(id, item("bad", 1)), "items[0].product_id");
    assertInvalid(
        request(id, item(productId(1), 1), item(productId(1), 2)),
        "items[1].product_id is a duplicate");
    ReserveStockRequest.Builder tooMany = ReserveStockRequest.newBuilder().setReservationId(id);
    for (int i = 0; i < 101; i++) {
      tooMany.addItems(item(productId(i), 1));
    }
    assertInvalid(tooMany.build(), "items must contain 1 to 100 entries");
  }

  private void assertInvalid(ReserveStockRequest request, String expectedMessagePart) {
    assertThatThrownBy(() -> inventory().reserveStock(request))
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> {
              assertThat(e.getStatus().getCode()).isEqualTo(Status.Code.INVALID_ARGUMENT);
              assertThat(e.getStatus().getDescription()).contains(expectedMessagePart);
            });
  }

  private static ReserveStockRequest request(String reservationId, ReservationItem... items) {
    return ReserveStockRequest.newBuilder()
        .setReservationId(reservationId)
        .addAllItems(List.of(items))
        .build();
  }

  private static ReservationItem item(String productId, int quantity) {
    return ReservationItem.newBuilder().setProductId(productId).setQuantity(quantity).build();
  }
}
