package com.ecommerce.inventory.grpc;

import com.ecommerce.inventory.grpc.v1.InventoryServiceGrpc;
import org.springframework.stereotype.Service;

/**
 * gRPC adapter for {@code inventory.v1.InventoryService}.
 *
 * <p>The service is registered (so it is listed by reflection and has a health status) but no RPC
 * is overridden yet: every call answers {@code UNIMPLEMENTED}, the generated base behaviour. The
 * RPCs are implemented by the phase 1 inventory tasks following docs/05.
 */
@Service
public class InventoryGrpcService extends InventoryServiceGrpc.InventoryServiceImplBase {}
