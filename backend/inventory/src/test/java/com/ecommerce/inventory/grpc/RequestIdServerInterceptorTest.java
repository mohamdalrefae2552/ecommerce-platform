package com.ecommerce.inventory.grpc;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ecommerce.inventory.grpc.v1.GetStockRequest;
import com.ecommerce.inventory.grpc.v1.GetStockResponse;
import com.ecommerce.inventory.grpc.v1.InventoryServiceGrpc;
import com.ecommerce.inventory.grpc.v1.ReserveStockRequest;
import io.grpc.ManagedChannel;
import io.grpc.Metadata;
import io.grpc.Server;
import io.grpc.ServerInterceptors;
import io.grpc.Status;
import io.grpc.StatusRuntimeException;
import io.grpc.inprocess.InProcessChannelBuilder;
import io.grpc.inprocess.InProcessServerBuilder;
import io.grpc.stub.MetadataUtils;
import io.grpc.stub.StreamObserver;
import java.io.IOException;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.slf4j.MDC;

class RequestIdServerInterceptorTest {

  private final AtomicReference<String> mdcSeenByHandler = new AtomicReference<>();
  private final AtomicReference<Metadata> responseHeaders = new AtomicReference<>();
  private final AtomicReference<Metadata> responseTrailers = new AtomicReference<>();

  private ExecutorService serverExecutor;
  private Server server;
  private ManagedChannel channel;

  @BeforeEach
  void startServer() throws IOException {
    String name = InProcessServerBuilder.generateName();
    // One server thread, so the test can check that the MDC is cleaned up on it after a call.
    serverExecutor = Executors.newSingleThreadExecutor();
    InventoryServiceGrpc.InventoryServiceImplBase service =
        new InventoryServiceGrpc.InventoryServiceImplBase() {
          @Override
          public void getStock(GetStockRequest request, StreamObserver<GetStockResponse> reply) {
            mdcSeenByHandler.set(MDC.get(RequestIdServerInterceptor.MDC_KEY));
            reply.onNext(GetStockResponse.getDefaultInstance());
            reply.onCompleted();
          }
        };
    server =
        InProcessServerBuilder.forName(name)
            .executor(serverExecutor)
            .addService(ServerInterceptors.intercept(service, new RequestIdServerInterceptor()))
            .build()
            .start();
    channel = InProcessChannelBuilder.forName(name).directExecutor().build();
  }

  @AfterEach
  void stopServer() throws InterruptedException {
    channel.shutdownNow().awaitTermination(5, TimeUnit.SECONDS);
    server.shutdownNow().awaitTermination(5, TimeUnit.SECONDS);
    serverExecutor.shutdownNow();
  }

  @Test
  void putsTheCallerRequestIdInTheMdcAndEchoesItInTheResponseHeaders() {
    stub("01JREQUEST-abc.123:x_y").getStock(GetStockRequest.getDefaultInstance());

    assertThat(mdcSeenByHandler.get()).isEqualTo("01JREQUEST-abc.123:x_y");
    assertThat(responseHeaders.get().get(RequestIdServerInterceptor.REQUEST_ID_HEADER))
        .isEqualTo("01JREQUEST-abc.123:x_y");
  }

  @Test
  void generatesAUuidWhenTheCallerSendsNoRequestId() {
    stub(null).getStock(GetStockRequest.getDefaultInstance());

    String generated = mdcSeenByHandler.get();
    assertThat(UUID.fromString(generated).toString()).isEqualTo(generated);
    assertThat(responseHeaders.get().get(RequestIdServerInterceptor.REQUEST_ID_HEADER))
        .isEqualTo(generated);
  }

  @Test
  void replacesARequestIdWithUnsafeCharactersOrOverlongValues() {
    stub("x\",\"level\":\"ERROR").getStock(GetStockRequest.getDefaultInstance());
    assertThat(UUID.fromString(mdcSeenByHandler.get())).isNotNull();

    stub("a".repeat(129)).getStock(GetStockRequest.getDefaultInstance());
    assertThat(UUID.fromString(mdcSeenByHandler.get())).isNotNull();
  }

  @Test
  void echoesTheRequestIdInTheTrailersWhenTheCallFailsBeforeSendingHeaders() {
    // ReserveStock is not overridden: the generated base answers UNIMPLEMENTED (trailers only).
    assertThatThrownBy(() -> stub("req-42").reserveStock(ReserveStockRequest.getDefaultInstance()))
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> assertThat(e.getStatus().getCode()).isEqualTo(Status.Code.UNIMPLEMENTED));

    assertThat(responseTrailers.get().get(RequestIdServerInterceptor.REQUEST_ID_HEADER))
        .isEqualTo("req-42");
  }

  @Test
  void removesTheRequestIdFromTheServerThreadMdcAfterTheCall() throws Exception {
    stub("req-7").getStock(GetStockRequest.getDefaultInstance());

    String leftOver =
        serverExecutor.submit(() -> MDC.get(RequestIdServerInterceptor.MDC_KEY)).get();
    assertThat(mdcSeenByHandler.get()).isEqualTo("req-7");
    assertThat(leftOver).isNull();
  }

  private InventoryServiceGrpc.InventoryServiceBlockingStub stub(String requestId) {
    Metadata outgoing = new Metadata();
    if (requestId != null) {
      outgoing.put(RequestIdServerInterceptor.REQUEST_ID_HEADER, requestId);
    }
    return InventoryServiceGrpc.newBlockingStub(channel)
        .withInterceptors(
            MetadataUtils.newAttachHeadersInterceptor(outgoing),
            MetadataUtils.newCaptureMetadataInterceptor(responseHeaders, responseTrailers))
        .withDeadlineAfter(5, TimeUnit.SECONDS);
  }
}
