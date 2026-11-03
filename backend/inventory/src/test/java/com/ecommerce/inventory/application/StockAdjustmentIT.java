package com.ecommerce.inventory.application;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.ecommerce.inventory.IntegrationTest;
import com.ecommerce.inventory.domain.StockWouldBecomeNegativeException;
import java.util.UUID;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;

/** AdjustStock (D-01): restock and write-off, idempotent by adjustment id (docs/05 section 2). */
class StockAdjustmentIT extends IntegrationTest {

  @Autowired private StockAdjustmentService adjustments;

  @Test
  void aFirstRestockRegistersTheProduct() {
    assertThat(adjustments.adjust(UUID.randomUUID(), productId(1), 5, "first delivery"))
        .isEqualTo(5);

    assertThat(isTracked(productId(1))).isTrue();
    assertThat(availableOf(productId(1))).isEqualTo(5);
  }

  @Test
  void aRepeatedAdjustmentIdAppliesTheDeltaOnlyOnceAndAnswersTheCurrentLevel() {
    UUID id = UUID.randomUUID();
    adjustments.adjust(id, productId(1), 5, "");

    assertThat(adjustments.adjust(id, productId(1), 5, "")).isEqualTo(5);
    assertThat(adjustments.adjust(UUID.randomUUID(), productId(1), -2, "damaged")).isEqualTo(3);
    assertThat(adjustments.adjust(id, productId(1), 5, "")).isEqualTo(3);
  }

  @Test
  void aWriteOffBeyondTheAvailableQuantityChangesNothing() {
    adjustments.adjust(UUID.randomUUID(), productId(1), 3, "");
    UUID writeOff = UUID.randomUUID();

    assertThatThrownBy(() -> adjustments.adjust(writeOff, productId(1), -4, "stock count"))
        .isInstanceOf(StockWouldBecomeNegativeException.class);

    assertThat(availableOf(productId(1))).isEqualTo(3);
    assertThat(
            jdbc.sql("SELECT count(*) FROM stock_adjustments WHERE id = :id")
                .param("id", writeOff)
                .query(Integer.class)
                .single())
        .isZero();
  }

  @Test
  void aWriteOffOnAnUnknownProductFailsAndLeavesItUntracked() {
    assertThatThrownBy(() -> adjustments.adjust(UUID.randomUUID(), productId(9), -1, ""))
        .isInstanceOf(StockWouldBecomeNegativeException.class);

    assertThat(isTracked(productId(9))).isFalse();
  }
}
