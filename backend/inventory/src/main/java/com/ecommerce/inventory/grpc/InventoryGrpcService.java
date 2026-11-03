package com.ecommerce.inventory.grpc;

import com.ecommerce.inventory.application.ReservationService;
import com.ecommerce.inventory.application.StockQueryService;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import com.ecommerce.inventory.domain.Shortage;
import com.ecommerce.inventory.domain.StockLevel;
import com.ecommerce.inventory.grpc.v1.GetStockRequest;
import com.ecommerce.inventory.grpc.v1.GetStockResponse;
import com.ecommerce.inventory.grpc.v1.InventoryServiceGrpc;
import com.ecommerce.inventory.grpc.v1.ReserveOutcome;
import com.ecommerce.inventory.grpc.v1.ReserveStockRequest;
import com.ecommerce.inventory.grpc.v1.ReserveStockResponse;
import io.grpc.stub.StreamObserver;
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

  public InventoryGrpcService(StockQueryService stockQuery, ReservationService reservations) {
    this.stockQuery = stockQuery;
    this.reservations = reservations;
  }

  @Override
  public void getStock(GetStockRequest request, StreamObserver<GetStockResponse> response) {
    List<String> productIds = RequestValidator.productIds(request.getProductIdsList());
    GetStockResponse.Builder reply = GetStockResponse.newBuilder();
    for (StockLevel level : stockQuery.getStock(productIds)) {
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
    ReserveStockResponse.Builder reply =
        ReserveStockResponse.newBuilder().setReservationId(request.getReservationId());
    switch (reservations.reserve(reservationId, lines)) {
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
}
