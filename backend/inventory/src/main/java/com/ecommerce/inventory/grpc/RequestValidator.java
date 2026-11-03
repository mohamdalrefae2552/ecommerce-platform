package com.ecommerce.inventory.grpc;

import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.regex.Pattern;

/**
 * Input rules of the gRPC contract (inventory.proto, docs/03 section 3). Every violation is an
 * {@link InvalidRequestException} whose message names the field, never the value sent.
 */
final class RequestValidator {

  /** ULID as in the OpenAPI {@code Ulid} schema: 26 Crockford base32 characters, upper case. */
  private static final Pattern ULID = Pattern.compile("[0-7][0-9A-HJKMNP-TV-Z]{25}");

  static final int MAX_PRODUCT_IDS = 100;

  private RequestValidator() {}

  /** GetStock: 1..100 product ULIDs without duplicates. */
  static List<String> productIds(List<String> productIds) {
    requireCount("product_ids", productIds.size(), MAX_PRODUCT_IDS);
    Set<String> seen = new HashSet<>();
    for (int i = 0; i < productIds.size(); i++) {
      String field = "product_ids[" + i + "]";
      String id = productId(field, productIds.get(i));
      if (!seen.add(id)) {
        throw new InvalidRequestException(field + " is a duplicate");
      }
    }
    return List.copyOf(productIds);
  }

  static String productId(String field, String value) {
    if (!ULID.matcher(value).matches()) {
      throw new InvalidRequestException(
          field + " must be a ULID (26 upper-case Crockford base32 characters)");
    }
    return value;
  }

  static void requireCount(String field, int count, int max) {
    if (count < 1 || count > max) {
      throw new InvalidRequestException(field + " must contain 1 to " + max + " entries");
    }
  }
}
