package com.ecommerce.inventory.persistence;

import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.Collection;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.stereotype.Repository;

/** {@code stock_items}: one row per product the inventory has ever registered. */
@Repository
public class StockRepository {

  private final JdbcClient jdbc;

  public StockRepository(JdbcClient jdbc) {
    this.jdbc = jdbc;
  }

  /** Available units of the given products that have a row; unknown products are absent. */
  public Map<String, Long> findAvailable(Collection<String> productIds) {
    return toMap(
        jdbc.sql(
                """
                SELECT product_id, available
                  FROM stock_items
                 WHERE product_id IN (:ids)
                """)
            .param("ids", productIds)
            .query(StockRepository::availability)
            .list());
  }

  /**
   * Locks the rows of the given products with {@code FOR UPDATE}, always in product_id order so
   * that concurrent transactions take the locks in the same order and cannot deadlock (ADR-0005).
   * Unknown products are absent from the result.
   */
  public Map<String, Long> lockAvailable(Collection<String> productIds) {
    return toMap(
        jdbc.sql(
                """
                SELECT product_id, available
                  FROM stock_items
                 WHERE product_id IN (:ids)
                 ORDER BY product_id
                   FOR UPDATE
                """)
            .param("ids", productIds)
            .query(StockRepository::availability)
            .list());
  }

  /** Takes units from a locked row; the CHECK (available >= 0) rejects any oversell. */
  public void decrement(String productId, int quantity) {
    jdbc.sql(
            """
            UPDATE stock_items
               SET available = available - :quantity, updated_at = now()
             WHERE product_id = :productId
            """)
        .param("quantity", quantity)
        .param("productId", productId)
        .update();
  }

  private static Map.Entry<String, Long> availability(ResultSet rs, int rowNum)
      throws SQLException {
    return Map.entry(rs.getString("product_id"), rs.getLong("available"));
  }

  private static Map<String, Long> toMap(List<Map.Entry<String, Long>> rows) {
    Map<String, Long> available = new LinkedHashMap<>();
    rows.forEach(row -> available.put(row.getKey(), row.getValue()));
    return available;
  }
}
