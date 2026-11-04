package com.ecommerce.inventory.domain;

/**
 * A line that cannot be served.
 *
 * @param productId product ULID of the short line
 * @param requested units requested
 * @param available units available at the time of the attempt (0 for an untracked product)
 */
public record Shortage(String productId, int requested, long available) {}
