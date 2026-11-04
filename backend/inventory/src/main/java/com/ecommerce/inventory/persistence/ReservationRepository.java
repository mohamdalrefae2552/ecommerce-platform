package com.ecommerce.inventory.persistence;

import com.ecommerce.inventory.domain.ReleaseReason;
import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReservationStatus;
import java.util.List;
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

  /**
   * Writes a RELEASED tombstone for an id seen for the first time, so a late ReserveStock with it
   * reserves nothing. False when the id already exists (proto rule 4).
   */
  public boolean insertTombstone(UUID reservationId, ReleaseReason reason) {
    return jdbc.sql(
                """
                INSERT INTO reservations (id, status, released_at, release_reason)
                VALUES (:id, 'RELEASED', now(), :reason)
                ON CONFLICT (id) DO NOTHING
                """)
            .param("id", reservationId)
            .param("reason", reason.name())
            .update()
        == 1;
  }

  /** Locks the reservation row and returns its status. */
  public Optional<ReservationStatus> lockStatus(UUID reservationId) {
    return jdbc.sql("SELECT status FROM reservations WHERE id = :id FOR UPDATE")
        .param("id", reservationId)
        .query(String.class)
        .optional()
        .map(ReservationStatus::valueOf);
  }

  /** The reserved lines, ordered by product_id. */
  public List<ReservationLine> findLines(UUID reservationId) {
    return jdbc.sql(
            """
            SELECT product_id, quantity
              FROM reservation_lines
             WHERE reservation_id = :id
             ORDER BY product_id
            """)
        .param("id", reservationId)
        .query(
            (rs, rowNum) -> new ReservationLine(rs.getString("product_id"), rs.getInt("quantity")))
        .list();
  }

  public void markReleased(UUID reservationId, ReleaseReason reason) {
    jdbc.sql(
            """
            UPDATE reservations
               SET status = 'RELEASED', released_at = now(), release_reason = :reason
             WHERE id = :id
            """)
        .param("reason", reason.name())
        .param("id", reservationId)
        .update();
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
