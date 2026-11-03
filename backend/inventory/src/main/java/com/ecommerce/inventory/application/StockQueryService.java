package com.ecommerce.inventory.application;

import com.ecommerce.inventory.domain.StockLevel;
import com.ecommerce.inventory.persistence.StockRepository;
import java.util.List;
import java.util.Map;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

/** GetStock: how many units of these products are left (R2-C1). */
@Service
public class StockQueryService {

  private final StockRepository stock;

  public StockQueryService(StockRepository stock) {
    this.stock = stock;
  }

  /**
   * One level per requested product, in the request order. A product never registered in the
   * inventory is reported as untracked with zero units.
   */
  @Transactional(readOnly = true)
  public List<StockLevel> getStock(List<String> productIds) {
    Map<String, Long> available = stock.findAvailable(productIds);
    return productIds.stream()
        .map(
            id ->
                available.containsKey(id)
                    ? StockLevel.tracked(id, available.get(id))
                    : StockLevel.untracked(id))
        .toList();
  }
}
