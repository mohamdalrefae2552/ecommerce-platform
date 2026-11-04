package com.ecommerce.inventory.grpc;

/** A request that breaks the contract's input rules; answered with {@code INVALID_ARGUMENT}. */
public final class InvalidRequestException extends RuntimeException {

  private static final long serialVersionUID = 1L;

  /**
   * @param message names the offending field and rule; never echoes the caller's value
   */
  public InvalidRequestException(String message) {
    super(message);
  }
}
