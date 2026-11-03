package com.ecommerce.inventory.grpc;

import com.ecommerce.inventory.application.ReservationService;
import com.ecommerce.inventory.application.StockAdjustmentService;
import com.ecommerce.inventory.application.StockQueryService;
import com.ecommerce.inventory.domain.ReleaseReason;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import com.ecommerce.inventory.domain.Shortage;
import com.ecommerce.inventory.domain.StockLevel;
import com.ecommerce.inventory.grpc.v1.AdjustStockRequest;
import com.ecommerce.inventory.grpc.v1.AdjustStockResponse;
import com.ecommerce.inventory.grpc.v1.GetStockRequest;
import com.ecommerce.inventory.grpc.v1.GetStockResponse;
import com.ecommerce.inventory.grpc.v1.InventoryServiceGrpc;
import com.ecommerce.inventory.grpc.v1.ReleaseOutcome;
import com.ecommerce.inventory.grpc.v1.ReleaseStockRequest;
import com.ecommerce.inventory.grpc.v1.ReleaseStockResponse;
import com.ecommerce.inventory.grpc.v1.ReserveOutcome;
import com.ecommerce.inventory.grpc.v1.ReserveStockRequest;
import com.ecommerce.inventory.grpc.v1.ReserveStockResponse;
import io.grpc.stub.StreamObserver;
import java.time.Duration;
import java.util.List;
import java.util.UUID;
import org.springframework.stereotype.Service;

/**
 * gRPC adapter for {@code inventory.v1.InventoryService}: validates the request, calls the
 * application layer and maps the result. Failures are thrown and mapped to a status by {@link
 * InventoryExceptionHandler}.
 */
@Service
public class InventoryGrpcService extends InventoryServiceGrpc.InventoryServiceImplBase {

  private final StockQueryService stockQuery;
  private final ReservationService reservations;
  private final StockAdjustmentService adjustments;

  public InventoryGrpcService(
      StockQueryService stockQuery,
      ReservationService reservations,
      StockAdjustmentService adjustments) {
    this.stockQuery = stockQuery;
    this.reservations = reservations;
    this.adjustments = adjustments;
  }

  @Override
  public void getStock(GetStockRequest request, StreamObserver<GetStockResponse> response) {
    List<String> productIds = RequestValidator.productIds(request.getProductIdsList());
    Duration budget = CallBudget.remaining();
    GetStockResponse.Builder reply = GetStockResponse.newBuilder();
    for (StockLevel level : stockQuery.getStock(productIds, budget)) {
      reply.addLevels(
          com.ecommerce.inventory.grpc.v1.StockLevel.newBuilder()
              .setProductId(level.productId())
              .setAvailable(level.available())
              .setTracked(level.tracked()));
    }
    response.onNext(reply.build());
    response.onCompleted();
  }

  @Override
  public void reserveStock(
      ReserveStockRequest request, StreamObserver<ReserveStockResponse> response) {
    UUID reservationId = RequestValidator.uuid("reservation_id", request.getReservationId());
    List<ReservationLine> lines = RequestValidator.reservationItems(request.getItemsList());
    Duration budget = CallBudget.remaining();
    ReserveStockResponse.Builder reply =
        ReserveStockResponse.newBuilder().setReservationId(request.getReservationId());
    switch (reservations.reserve(reservationId, lines, budget)) {
      case ReserveResult.Reserved reserved ->
          reply.setOutcome(ReserveOutcome.RESERVE_OUTCOME_RESERVED);
      case ReserveResult.AlreadyReleased released ->
          reply.setOutcome(ReserveOutcome.RESERVE_OUTCOME_RESERVATION_ALREADY_RELEASED);
      case ReserveResult.InsufficientStock insufficient -> {
        reply.setOutcome(ReserveOutcome.RESERVE_OUTCOME_INSUFFICIENT_STOCK);
        for (Shortage shortage : insufficient.shortages()) {
          reply.addShortages(
              com.ecommerce.inventory.grpc.v1.Shortage.newBuilder()
                  .setProductId(shortage.productId())
                  .setRequested(shortage.requested())
                  .setAvailable(shortage.available()));
        }
      }
    }
    response.onNext(reply.build());
    response.onCompleted();
  }

  @Override
  public void releaseStock(
      ReleaseStockRequest request, StreamObserver<ReleaseStockResponse> response) {
    UUID reservationId = RequestValidator.uuid("reservation_id", request.getReservationId());
    ReleaseReason reason = RequestValidator.releaseReason(request.getReason());
    Duration budget = CallBudget.remaining();
    ReleaseOutcome outcome =
        switch (reservations.release(reservationId, reason, budget)) {
          case RELEASED -> ReleaseOutcome.RELEASE_OUTCOME_RELEASED;
          case ALREADY_RELEASED -> ReleaseOutcome.RELEASE_OUTCOME_ALREADY_RELEASED;
          case NOT_FOUND_TOMBSTONED -> ReleaseOutcome.RELEASE_OUTCOME_NOT_FOUND_TOMBSTONED;
        };
    response.onNext(
        ReleaseStockResponse.newBuilder()
            .setReservationId(request.getReservationId())
            .setOutcome(outcome)
            .build());
    response.onCompleted();
  }

  @Override
  public void adjustStock(
      AdjustStockRequest request, StreamObserver<AdjustStockResponse> response) {
    UUID adjustmentId = RequestValidator.uuid("adjustment_id", request.getAdjustmentId());
    String productId = RequestValidator.productId("product_id", request.getProductId());
    long delta = RequestValidator.delta(request.getDelta());
    String note = RequestValidator.note(request.getNote());
    Duration budget = CallBudget.remaining();
    long available = adjustments.adjust(adjustmentId, productId, delta, note, budget);
    response.onNext(
        AdjustStockResponse.newBuilder()
            .setAdjustmentId(request.getAdjustmentId())
            .setProductId(productId)
            .setAvailable(available)
            .build());
    response.onCompleted();
  }
}
