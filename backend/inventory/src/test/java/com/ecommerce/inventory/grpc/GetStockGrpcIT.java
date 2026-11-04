package com.ecommerce.inventory.grpc;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.grpc.v1.GetStockRequest;
import com.ecommerce.inventory.grpc.v1.GetStockResponse;
import com.ecommerce.inventory.grpc.v1.StockLevel;
import io.grpc.Status;
import io.grpc.StatusRuntimeException;
import java.util.Collections;
import java.util.List;
import java.util.stream.IntStream;
import org.junit.jupiter.api.Test;

class GetStockGrpcIT extends IntegrationTest {

  @Test
  void answersTheLevelOfEveryRequestedProductInOrder() {
    givenStock(productId(1), 3);

    GetStockResponse response =
        inventory()
            .getStock(
                GetStockRequest.newBuilder()
                    .addProductIds(productId(2))
                    .addProductIds(productId(1))
                    .build());

    assertThat(response.getLevelsList())
        .containsExactly(
            StockLevel.newBuilder().setProductId(productId(2)).setTracked(false).build(),
            StockLevel.newBuilder()
                .setProductId(productId(1))
                .setAvailable(3)
                .setTracked(true)
                .build());
  }

  @Test
  void rejectsInvalidProductListsWithInvalidArgument() {
    assertInvalid(List.of(), "product_ids must contain 1 to 100 entries");
    assertInvalid(
        IntStream.rangeClosed(1, 101).mapToObj(IntegrationTest::productId).toList(),
        "product_ids must contain 1 to 100 entries");
    assertInvalid(List.of(productId(1), productId(1)), "product_ids[1] is a duplicate");
    assertInvalid(List.of(productId(1).toLowerCase(java.util.Locale.ROOT)), "product_ids[0]");
    assertInvalid(Collections.nCopies(1, "not-a-ulid"), "product_ids[0]");
  }

  private void assertInvalid(List<String> productIds, String expectedMessagePart) {
    GetStockRequest request = GetStockRequest.newBuilder().addAllProductIds(productIds).build();
    assertThatThrownBy(() -> inventory().getStock(request))
        .isInstanceOfSatisfying(
            StatusRuntimeException.class,
            e -> {
              assertThat(e.getStatus().getCode()).isEqualTo(Status.Code.INVALID_ARGUMENT);
              assertThat(e.getStatus().getDescription()).contains(expectedMessagePart);
            });
  }
}
