package com.ecommerce.inventory.grpc;

import com.ecommerce.inventory.application.StockQueryService;
import com.ecommerce.inventory.domain.StockLevel;
import com.ecommerce.inventory.grpc.v1.GetStockRequest;
import com.ecommerce.inventory.grpc.v1.GetStockResponse;
import com.ecommerce.inventory.grpc.v1.InventoryServiceGrpc;
import io.grpc.stub.StreamObserver;
import java.util.List;
import org.springframework.stereotype.Service;

/**
 * gRPC adapter for {@code inventory.v1.InventoryService}: validates the request, calls the
 * application layer and maps the result. Failures are thrown and mapped to a status by {@link
 * InventoryExceptionHandler}.
 */
@Service
public class InventoryGrpcService extends InventoryServiceGrpc.InventoryServiceImplBase {

  private final StockQueryService stockQuery;

  public InventoryGrpcService(StockQueryService stockQuery) {
    this.stockQuery = stockQuery;
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
}
