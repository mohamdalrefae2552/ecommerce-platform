package com.ecommerce.inventory.persistence;

import java.util.UUID;
import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.stereotype.Repository;

/** {@code stock_adjustments}: the audit trail of administrative changes. */
@Repository
public class AdjustmentRepository {

  private final JdbcClient jdbc;

  public AdjustmentRepository(JdbcClient jdbc) {
    this.jdbc = jdbc;
  }

  /** Records the adjustment; false when its id was already used (a repeat). */
  public boolean insert(UUID adjustmentId, String productId, long delta, String note) {
    return jdbc.sql(
                """
                INSERT INTO stock_adjustments (id, product_id, delta, note)
                VALUES (:id, :productId, :delta, :note)
                ON CONFLICT (id) DO NOTHING
                """)
            .param("id", adjustmentId)
            .param("productId", productId)
            .param("delta", delta)
            .param("note", note)
            .update()
        == 1;
  }
}
