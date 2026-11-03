package com.ecommerce.inventory;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.boot.context.properties.ConfigurationPropertiesScan;

/** Inventory service: the only owner of stock data, reachable through gRPC only. */
@SpringBootApplication
@ConfigurationPropertiesScan
public class InventoryApplication {

  public static void main(String[] args) {
    SpringApplication.run(InventoryApplication.class, args);
  }
}
