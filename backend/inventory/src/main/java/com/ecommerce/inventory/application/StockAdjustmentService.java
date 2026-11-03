package com.ecommerce.inventory.application;

import com.ecommerce.inventory.domain.StockWouldBecomeNegativeException;
import com.ecommerce.inventory.persistence.AdjustmentRepository;
import com.ecommerce.inventory.persistence.StockRepository;
import java.util.List;
import java.util.UUID;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

/** AdjustStock (decision D-01): restock or write-off by an administrator, docs/05 section 2. */
@Service
public class StockAdjustmentService {

  private final StockRepository stock;
  private final AdjustmentRepository adjustments;

  public StockAdjustmentService(StockRepository stock, AdjustmentRepository adjustments) {
    this.stock = stock;
    this.adjustments = adjustments;
  }

  /**
   * Applies the delta once per adjustment id and returns the available units afterwards; a repeat
   * returns the current level without applying the delta again.
   *
   * @throws StockWouldBecomeNegativeException when the result would be negative; everything is
   *     rolled back, including the row of a product seen for the first time
   */
  @Transactional
  public long adjust(UUID adjustmentId, String productId, long delta, String note) {
    stock.insertIfAbsent(productId);
    if (!adjustments.insert(adjustmentId, productId, delta, note)) {
      return stock.findAvailable(List.of(productId)).get(productId);
    }
    return stock.add(productId, delta);
  }
}
