package com.ecommerce.inventory;

import org.springframework.boot.test.context.TestConfiguration;
import org.springframework.boot.testcontainers.service.connection.ServiceConnection;
import org.springframework.context.annotation.Bean;
import org.testcontainers.postgresql.PostgreSQLContainer;

/**
 * A real PostgreSQL, the same image as {@code db-inventory} in compose.yaml. Import it in every
 * test that needs the database; the Spring test context (and so the container) is shared between
 * test classes with the same configuration.
 */
@TestConfiguration(proxyBeanMethods = false)
public class TestcontainersConfiguration {

  static final String POSTGRES_IMAGE = "postgres:18.6-alpine3.24";

  @Bean
  @ServiceConnection
  PostgreSQLContainer postgres() {
    return new PostgreSQLContainer(POSTGRES_IMAGE);
  }
}
