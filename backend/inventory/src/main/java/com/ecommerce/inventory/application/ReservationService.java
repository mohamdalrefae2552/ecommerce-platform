package com.ecommerce.inventory.application;

import com.ecommerce.inventory.domain.ReservationLine;
import com.ecommerce.inventory.domain.ReserveResult;
import com.ecommerce.inventory.domain.Shortage;
import com.ecommerce.inventory.domain.Shortages;
import com.ecommerce.inventory.persistence.ReservationRepository;
import com.ecommerce.inventory.persistence.StockRepository;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;
import org.springframework.transaction.interceptor.TransactionAspectSupport;

/** ReserveStock (R2-C2): the algorithm of docs/05 section 2, in one READ COMMITTED transaction. */
@Service
public class ReservationService {

  private final ReservationRepository reservations;
  private final StockRepository stock;

  public ReservationService(ReservationRepository reservations, StockRepository stock) {
    this.reservations = reservations;
    this.stock = stock;
  }

  /**
   * Reserves every line or nothing. Idempotent by reservation id: a repeat is answered from the
   * stored reservation, and an id released earlier (tombstone) reserves nothing.
   *
   * @param lines distinct products, already validated
   */
  @Transactional
  public ReserveResult reserve(UUID reservationId, List<ReservationLine> lines) {
    if (!reservations.insertReserved(reservationId)) {
      return switch (reservations.findStatus(reservationId).orElseThrow()) {
        case RESERVED -> new ReserveResult.Reserved();
        case RELEASED -> new ReserveResult.AlreadyReleased();
      };
    }

    List<ReservationLine> ordered =
        lines.stream().sorted(Comparator.comparing(ReservationLine::productId)).toList();
    Map<String, Long> available =
        stock.lockAvailable(ordered.stream().map(ReservationLine::productId).toList());

    List<Shortage> shortages = Shortages.of(ordered, available);
    if (!shortages.isEmpty()) {
      // Full rollback: the reservations row claimed above disappears too.
      TransactionAspectSupport.currentTransactionStatus().setRollbackOnly();
      return new ReserveResult.InsufficientStock(shortages);
    }

    for (ReservationLine line : ordered) {
      stock.decrement(line.productId(), line.quantity());
      reservations.insertLine(reservationId, line.productId(), line.quantity());
    }
    return new ReserveResult.Reserved();
  }
}
