package com.ecommerce.inventory.persistence;

import com.ecommerce.inventory.domain.ReservationStatus;
import java.util.Optional;
import java.util.UUID;
import org.springframework.jdbc.core.simple.JdbcClient;
import org.springframework.stereotype.Repository;

/** {@code reservations} and {@code reservation_lines}. */
@Repository
public class ReservationRepository {

  private final JdbcClient jdbc;

  public ReservationRepository(JdbcClient jdbc) {
    this.jdbc = jdbc;
  }

  /**
   * Claims the id for a new reservation. False when the id already exists; PostgreSQL makes the
   * insert wait for a concurrent uncommitted transaction holding the same id.
   */
  public boolean insertReserved(UUID reservationId) {
    return jdbc.sql(
                """
                INSERT INTO reservations (id, status)
                VALUES (:id, 'RESERVED')
                ON CONFLICT (id) DO NOTHING
                """)
            .param("id", reservationId)
            .update()
        == 1;
  }

  public Optional<ReservationStatus> findStatus(UUID reservationId) {
    return jdbc.sql("SELECT status FROM reservations WHERE id = :id")
        .param("id", reservationId)
        .query(String.class)
        .optional()
        .map(ReservationStatus::valueOf);
  }

  public void insertLine(UUID reservationId, String productId, int quantity) {
    jdbc.sql(
            """
            INSERT INTO reservation_lines (reservation_id, product_id, quantity)
            VALUES (:reservationId, :productId, :quantity)
            """)
        .param("reservationId", reservationId)
        .param("productId", productId)
        .param("quantity", quantity)
        .update();
  }
}
