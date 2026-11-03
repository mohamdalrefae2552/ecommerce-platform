package com.ecommerce.inventory;

import static org.assertj.core.api.Assertions.assertThat;
import static org.awaitility.Awaitility.await;

import com.ecommerce.inventory.grpc.RequestIdServerInterceptor;
import com.ecommerce.inventory.grpc.v1.GetStockRequest;
import io.grpc.Metadata;
import io.grpc.health.v1.HealthCheckRequest;
import io.grpc.health.v1.HealthCheckResponse.ServingStatus;
import io.grpc.health.v1.HealthGrpc;
import io.grpc.reflection.v1.ServerReflectionGrpc;
import io.grpc.reflection.v1.ServerReflectionRequest;
import io.grpc.reflection.v1.ServerReflectionResponse;
import io.grpc.reflection.v1.ServiceResponse;
import io.grpc.stub.MetadataUtils;
import io.grpc.stub.StreamObserver;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;
import java.util.List;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;
import org.junit.jupiter.api.Test;
import org.springframework.boot.test.web.server.LocalServerPort;

/** The service boots on a real PostgreSQL and exposes health, reflection and Actuator. */
class InventoryApplicationIT extends IntegrationTest {

  private static final String INVENTORY_SERVICE = "inventory.v1.InventoryService";

  @LocalServerPort private int managementPort;

  @Test
  void connectsToPostgresWithTheConfiguredSessionTimeouts() {
    assertThat(jdbc.sql("SHOW server_version_num").query(Integer.class).single())
        .isGreaterThanOrEqualTo(180_000);
    assertThat(jdbc.sql("SHOW lock_timeout").query(String.class).single()).isEqualTo("1500ms");
    assertThat(jdbc.sql("SHOW statement_timeout").query(String.class).single()).isEqualTo("1800ms");
  }

  @Test
  void reportsServingThroughTheGrpcHealthService() {
    // Statuses are refreshed from the application health by a scheduler, so poll briefly.
    await()
        .atMost(Duration.ofSeconds(20))
        .untilAsserted(
            () -> {
              assertThat(healthOf("")).isEqualTo(ServingStatus.SERVING);
              assertThat(healthOf(INVENTORY_SERVICE)).isEqualTo(ServingStatus.SERVING);
            });
  }

  private ServingStatus healthOf(String service) {
    return HealthGrpc.newBlockingStub(channel)
        .withDeadlineAfter(5, TimeUnit.SECONDS)
        .check(HealthCheckRequest.newBuilder().setService(service).build())
        .getStatus();
  }

  @Test
  void listsTheInventoryAndHealthServicesThroughReflection() throws Exception {
    assertThat(listServicesByReflection()).contains(INVENTORY_SERVICE, "grpc.health.v1.Health");
  }

  @Test
  void echoesTheCallerRequestIdInTheResponseHeaders() {
    Metadata outgoing = new Metadata();
    outgoing.put(RequestIdServerInterceptor.REQUEST_ID_HEADER, "it-request-1");
    AtomicReference<Metadata> headers = new AtomicReference<>();
    AtomicReference<Metadata> trailers = new AtomicReference<>();

    inventory()
        .withInterceptors(
            MetadataUtils.newAttachHeadersInterceptor(outgoing),
            MetadataUtils.newCaptureMetadataInterceptor(headers, trailers))
        .getStock(GetStockRequest.newBuilder().addProductIds(productId(1)).build());

    assertThat(headers.get().get(RequestIdServerInterceptor.REQUEST_ID_HEADER))
        .isEqualTo("it-request-1");
  }

  @Test
  void exposesOnlyTheActuatorHealthEndpointOverHttp() throws Exception {
    try (HttpClient http = HttpClient.newHttpClient()) {
      HttpResponse<String> health = get(http, "/actuator/health");
      assertThat(health.statusCode()).isEqualTo(200);
      assertThat(health.body()).contains("\"status\":\"UP\"");

      assertThat(get(http, "/actuator/health/readiness").statusCode()).isEqualTo(200);
      assertThat(get(http, "/actuator/env").statusCode()).isEqualTo(404);
    }
  }

  private HttpResponse<String> get(HttpClient http, String path) throws Exception {
    HttpRequest request =
        HttpRequest.newBuilder(URI.create("http://localhost:" + managementPort + path)).build();
    return http.send(request, HttpResponse.BodyHandlers.ofString());
  }

  private List<String> listServicesByReflection() throws Exception {
    CompletableFuture<List<String>> services = new CompletableFuture<>();
    StreamObserver<ServerReflectionRequest> requests =
        ServerReflectionGrpc.newStub(channel)
            .withDeadlineAfter(5, TimeUnit.SECONDS)
            .serverReflectionInfo(
                new StreamObserver<>() {
                  @Override
                  public void onNext(ServerReflectionResponse response) {
                    services.complete(
                        response.getListServicesResponse().getServiceList().stream()
                            .map(ServiceResponse::getName)
                            .toList());
                  }

                  @Override
                  public void onError(Throwable error) {
                    services.completeExceptionally(error);
                  }

                  @Override
                  public void onCompleted() {
                    // The single response above is all this test needs.
                  }
                });
    requests.onNext(ServerReflectionRequest.newBuilder().setListServices("").build());
    requests.onCompleted();
    return services.get(5, TimeUnit.SECONDS);
  }
}
