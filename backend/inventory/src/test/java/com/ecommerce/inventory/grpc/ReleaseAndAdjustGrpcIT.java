package com.ecommerce.inventory.grpc;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.grpc.v1.AdjustStockRequest;
import com.ecommerce.inventory.grpc.v1.AdjustStockResponse;
import com.ecommerce.inventory.grpc.v1.ReleaseOutcome;
import com.ecommerce.inventory.grpc.v1.ReleaseReason;
import com.ecommerce.inventory.grpc.v1.ReleaseStockRequest;
import com.ecommerce.inventory.grpc.v1.ReleaseStockResponse;
import com.ecommerce.inventory.grpc.v1.ReservationItem;
import com.ecommerce.inventory.grpc.v1.ReserveStockRequest;
import io.grpc.Status;
import io.grpc.StatusRuntimeException;
import java.util.UUID;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.function.Executable;

class ReleaseAndAdjustGrpcIT extends IntegrationTest {

  @Test
  void releaseReportsEachOutcomeAndEchoesTheReservationId() {
    givenStock(productId(1), 2);
    String id = UUID.randomUUID().toString();
    inventory()
        .reserveStock(
            ReserveStockRequest.newBuilder()
                .setReservationId(id)
                .addItems(ReservationItem.newBuilder().setProductId(productId(1)).setQuantity(2))
                .build());

    ReleaseStockResponse first = inventory().releaseStock(release(id));
    ReleaseStockResponse second = inventory().releaseStock(release(id));
    String unknown = UUID.randomUUID().toString();
    ReleaseStockResponse tombstoned = inventory().releaseStock(release(unknown));

    assertThat(first.getReservationId()).isEqualTo(id);
    assertThat(first.getOutcome()).isEqualTo(ReleaseOutcome.RELEASE_OUTCOME_RELEASED);
    assertThat(second.getOutcome()).isEqualTo(ReleaseOutcome.RELEASE_OUTCOME_ALREADY_RELEASED);
    assertThat(tombstoned.getReservationId()).isEqualTo(unknown);
    assertThat(tombstoned.getOutcome())
        .isEqualTo(ReleaseOutcome.RELEASE_OUTCOME_NOT_FOUND_TOMBSTONED);
    assertThat(availableOf(productId(1))).isEqualTo(2);
  }

  @Test
  void releaseRejectsAMissingReasonOrABadId() {
    assertStatus(
        () ->
            inventory()
                .releaseStock(
                    ReleaseStockRequest.newBuilder()
                        .setReservationId(UUID.randomUUID().toString())
                        .build()),
        Status.Code.INVALID_ARGUMENT,
        "reason");
    assertStatus(
        () -> inventory().releaseStock(release("nope")),
        Status.Code.INVALID_ARGUMENT,
        "reservation_id");
  }

  @Test
  void adjustReturnsTheNewLevelAndEchoesTheIds() {
    String id = UUID.randomUUID().toString();

    AdjustStockResponse response = inventory().adjustStock(adjust(id, productId(1), 7, "restock"));

    assertThat(response.getAdjustmentId()).isEqualTo(id);
    assertThat(response.getProductId()).isEqualTo(productId(1));
    assertThat(response.getAvailable()).isEqualTo(7);
  }

  @Test
  void adjustBelowZeroIsAFailedPrecondition() {
    givenStock(productId(1), 1);

    assertStatus(
        () -> inventory().adjustStock(adjust(UUID.randomUUID().toString(), productId(1), -2, "")),
        Status.Code.FAILED_PRECONDITION,
        "negative");
    assertThat(availableOf(productId(1))).isEqualTo(1);
  }

  @Test
  void adjustRejectsRequestsThatBreakTheContract() {
    String id = UUID.randomUUID().toString();
    assertInvalidAdjust(adjust("x", productId(1), 1, ""), "adjustment_id");
    assertInvalidAdjust(adjust(id, "x", 1, ""), "product_id");
    assertInvalidAdjust(adjust(id, productId(1), 0, ""), "delta");
    assertInvalidAdjust(adjust(id, productId(1), 1_000_001, ""), "delta");
    assertInvalidAdjust(adjust(id, productId(1), -1_000_001, ""), "delta");
    assertInvalidAdjust(adjust(id, productId(1), 1, "n".repeat(201)), "note");
    assertThat(inventory().adjustStock(adjust(id, productId(1), 1_000_000, "n".repeat(200))))
        .extracting(AdjustStockResponse::getAvailable)
        .isEqualTo(1_000_000L);
  }

  private void assertInvalidAdjust(AdjustStockRequest request, String field) {
    assertStatus(() -> inventory().adjustStock(request), Status.Code.INVALID_ARGUMENT, field);
  }

  private static void assertStatus(Executable call, Status.Code code, String descriptionPart) {
    assertThatThrownBy(call::execute)
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> {
              assertThat(e.getStatus().getCode()).isEqualTo(code);
              assertThat(e.getStatus().getDescription()).contains(descriptionPart);
            });
  }

  private static ReleaseStockRequest release(String reservationId) {
    return ReleaseStockRequest.newBuilder()
        .setReservationId(reservationId)
        .setReason(ReleaseReason.RELEASE_REASON_ORDER_CANCELLED)
        .build();
  }

  private static AdjustStockRequest adjust(
      String adjustmentId, String productId, long delta, String note) {
    return AdjustStockRequest.newBuilder()
        .setAdjustmentId(adjustmentId)
        .setProductId(productId)
        .setDelta(delta)
        .setNote(note)
        .build();
  }
}
