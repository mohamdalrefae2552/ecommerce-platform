package com.ecommerce.inventory.grpc;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.awaitility.Awaitility.await;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.grpc.v1.InventoryServiceGrpc;
import com.ecommerce.inventory.grpc.v1.ReservationItem;
import com.ecommerce.inventory.grpc.v1.ReserveStockRequest;
import io.grpc.Status;
import io.grpc.StatusRuntimeException;
import java.time.Duration;
import java.util.UUID;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.transaction.PlatformTransactionManager;
import org.springframework.transaction.support.TransactionTemplate;

/**
 * Lock waits are bounded by the configured lock_timeout (ABORTED, docs/03 section 3) and by the
 * caller's deadline: once the caller has given up, the server must not reserve anything.
 */
class TimeoutsAndDeadlinesGrpcIT extends IntegrationTest {

  @Autowired private PlatformTransactionManager transactionManager;

  private ExecutorService lockHolder;

  @BeforeEach
  void startLockHolder() {
    lockHolder = Executors.newSingleThreadExecutor();
  }

  @AfterEach
  void stopLockHolder() {
    lockHolder.shutdownNow();
  }

  @Test
  void aRowLockedLongerThanTheLockTimeoutAbortsTheCall() throws Exception {
    String product = productId(1);
    givenStock(product, 5);
    Future<?> holder = holdRowLock(product, Duration.ofSeconds(4));

    long started = System.nanoTime();
    assertThatThrownBy(() -> inventory().reserveStock(reserve(product)))
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> assertThat(e.getStatus().getCode()).isEqualTo(Status.Code.ABORTED));
    Duration waited = Duration.ofNanos(System.nanoTime() - started);

    // lock_timeout is 1500 ms: well before the 4 s lock and the 10 s client deadline.
    assertThat(waited).isBetween(Duration.ofMillis(1000), Duration.ofMillis(3500));
    holder.get(10, TimeUnit.SECONDS);
    assertNothingReserved(product);
  }

  @Test
  void nothingIsReservedAfterTheCallerDeadlineHasPassed() throws Exception {
    String product = productId(1);
    givenStock(product, 5);
    // Released after 1 s: within lock_timeout, but long after the caller's 400 ms deadline.
    Future<?> holder = holdRowLock(product, Duration.ofSeconds(1));

    assertThatThrownBy(
            () ->
                InventoryServiceGrpc.newBlockingStub(channel)
                    .withDeadlineAfter(400, TimeUnit.MILLISECONDS)
                    .reserveStock(reserve(product)))
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> assertThat(e.getStatus().getCode()).isEqualTo(Status.Code.DEADLINE_EXCEEDED));

    holder.get(10, TimeUnit.SECONDS);
    // A server ignoring the deadline would get the lock now and reserve for a caller that is gone.
    await()
        .during(Duration.ofMillis(800))
        .atMost(Duration.ofSeconds(3))
        .untilAsserted(() -> assertNothingReserved(product));
  }

  private Future<?> holdRowLock(String productId, Duration holdFor) throws InterruptedException {
    CountDownLatch locked = new CountDownLatch(1);
    TransactionTemplate transaction = new TransactionTemplate(transactionManager);
    Future<?> holder =
        lockHolder.submit(
            () ->
                transaction.executeWithoutResult(
                    status -> {
                      jdbc.sql("SELECT set_config('statement_timeout', '0', true)")
                          .query()
                          .listOfRows();
                      jdbc.sql(
                              "SELECT product_id FROM stock_items WHERE product_id = :id FOR UPDATE")
                          .param("id", productId)
                          .query()
                          .listOfRows();
                      locked.countDown();
                      jdbc.sql("SELECT pg_sleep(:seconds)")
                          .param("seconds", holdFor.toMillis() / 1000.0)
                          .query()
                          .listOfRows();
                    }));
    assertThat(locked.await(10, TimeUnit.SECONDS)).isTrue();
    return holder;
  }

  private void assertNothingReserved(String productId) {
    assertThat(availableOf(productId)).isEqualTo(5);
    assertThat(jdbc.sql("SELECT count(*) FROM reservations").query(Integer.class).single())
        .isZero();
  }

  private static ReserveStockRequest reserve(String productId) {
    return ReserveStockRequest.newBuilder()
        .setReservationId(UUID.randomUUID().toString())
        .addItems(ReservationItem.newBuilder().setProductId(productId).setQuantity(1))
        .build();
  }
}
