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
