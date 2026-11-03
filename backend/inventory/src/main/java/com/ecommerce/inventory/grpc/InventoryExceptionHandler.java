package com.ecommerce.inventory.grpc;

import com.ecommerce.inventory.domain.StockWouldBecomeNegativeException;
import io.grpc.Status;
import io.grpc.StatusException;
import io.grpc.StatusRuntimeException;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.grpc.server.exception.GrpcExceptionHandler;
import org.springframework.stereotype.Component;

/**
 * Maps every exception leaving an RPC to a gRPC status (docs/03 section 3). Anything unexpected
 * becomes {@code INTERNAL} with a generic message; the details only go to the log, which carries
 * the request id.
 */
@Component
public class InventoryExceptionHandler implements GrpcExceptionHandler {

  private static final Logger log = LoggerFactory.getLogger(InventoryExceptionHandler.class);

  @Override
  public StatusException handleException(Throwable exception) {
    return switch (exception) {
      case StatusException e -> e;
      case StatusRuntimeException e -> e.getStatus().asException(e.getTrailers());
      case InvalidRequestException e ->
          Status.INVALID_ARGUMENT.withDescription(e.getMessage()).asException();
      case StockWouldBecomeNegativeException e ->
          Status.FAILED_PRECONDITION
              .withDescription("the adjustment would make the available quantity negative")
              .asException();
      default -> {
        log.error("Unexpected failure in a gRPC call", exception);
        yield Status.INTERNAL.withDescription("internal error").asException();
      }
    };
  }
}
