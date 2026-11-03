package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.domain.StockLevel;
import java.util.List;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

class StockQueryServiceIT extends IntegrationTest {

  @Autowired private StockQueryService stockQuery;

  @Test
  void returnsOneLevelPerProductInTheRequestOrderWithUnknownProductsUntracked() {
    givenStock(productId(1), 7);
    givenStock(productId(2), 0);

    List<StockLevel> levels =
        stockQuery.getStock(List.of(productId(3), productId(2), productId(1)));

    assertThat(levels)
        .containsExactly(
            StockLevel.untracked(productId(3)),
            StockLevel.tracked(productId(2), 0),
            StockLevel.tracked(productId(1), 7));
  }
}
