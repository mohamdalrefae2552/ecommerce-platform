package com.ecommerce.inventory;

import com.ecommerce.inventory.grpc.v1.InventoryServiceGrpc;
import io.grpc.ManagedChannel;
import io.grpc.ManagedChannelBuilder;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.grpc.test.autoconfigure.LocalGrpcServerPort;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.context.annotation.Import;
import org.springframework.jdbc.core.simple.JdbcClient;

/**
 * Base of every integration test: the whole service on a real PostgreSQL (one shared Spring context
 * and container), a real gRPC socket, and an empty database before each test.
 */
@SpringBootTest(
    webEnvironment = SpringBootTest.WebEnvironment.RANDOM_PORT,
    properties = {
      "spring.grpc.server.port=0",
      // Concurrency tests run more callers than pool connections on a slow Docker VM; they
      // measure locking, not pool sizing, so callers may queue for a connection longer than the
      // production fail-fast wait.
      "spring.datasource.hikari.connection-timeout=10000"
    })
@Import(TestcontainersConfiguration.class)
public abstract class IntegrationTest {

  @LocalGrpcServerPort protected int grpcPort;

  @Autowired protected JdbcClient jdbc;

  protected ManagedChannel channel;

  @BeforeEach
  void resetDatabaseAndOpenChannel() {
    jdbc.sql("TRUNCATE stock_adjustments, reservation_lines, reservations, stock_items").update();
    channel = ManagedChannelBuilder.forAddress("localhost", grpcPort).usePlaintext().build();
  }

  @AfterEach
  void closeChannel() throws InterruptedException {
    channel.shutdownNow().awaitTermination(5, TimeUnit.SECONDS);
  }

  /** A blocking stub with a generous deadline (the contract requires one on every call). */
  protected InventoryServiceGrpc.InventoryServiceBlockingStub inventory() {
    return InventoryServiceGrpc.newBlockingStub(channel).withDeadlineAfter(10, TimeUnit.SECONDS);
  }

  /** A valid product ULID whose sort order follows {@code n}. */
  protected static String productId(int n) {
    return "01JC3Z8X7Q4M2N5P6R7S8T9" + String.format("%03d", n);
  }

  protected void givenStock(String productId, long available) {
    jdbc.sql("INSERT INTO stock_items (product_id, available) VALUES (:id, :available)")
        .param("id", productId)
        .param("available", available)
        .update();
  }

  protected long availableOf(String productId) {
    return jdbc.sql("SELECT available FROM stock_items WHERE product_id = :id")
        .param("id", productId)
        .query(Long.class)
        .single();
  }

  protected boolean isTracked(String productId) {
    return jdbc.sql("SELECT count(*) FROM stock_items WHERE product_id = :id")
            .param("id", productId)
            .query(Integer.class)
            .single()
        == 1;
  }
}
