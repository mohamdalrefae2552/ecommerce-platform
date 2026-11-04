# Inventory service image (docs/01 section 2). Built by compose with
#   context: backend/inventory   additional_contexts: contracts=./contracts
# The Gradle protobuf plugin reads ../../contracts/proto, so the build stage
# recreates the repository layout under /work.

# ---------------------------------------------------------------- build --
FROM eclipse-temurin:25.0.5_8-jdk-noble AS build
ENV GRADLE_USER_HOME=/gradle
WORKDIR /work/backend/inventory

# Wrapper and build scripts first: the dependency layer is rebuilt only when
# they change.
COPY gradlew settings.gradle.kts build.gradle.kts ./
COPY gradle ./gradle
RUN --mount=type=cache,target=/gradle ./gradlew --no-daemon --quiet dependencies > /dev/null

COPY --from=contracts proto /work/contracts/proto
COPY src ./src
RUN --mount=type=cache,target=/gradle ./gradlew --no-daemon bootJar \
 && java -Djarmode=tools -jar build/libs/inventory.jar extract --layers --destination /extracted

# -------------------------------------------------------------- runtime --
FROM eclipse-temurin:25.0.5_8-jre-noble AS runtime
# wget is the only addition: the compose healthcheck calls Actuator with it.
RUN apt-get -o Acquire::Retries=5 update \
 && apt-get -o Acquire::Retries=5 install -y --no-install-recommends wget \
 && rm -rf /var/lib/apt/lists/* \
 && groupadd --system --gid 10001 inventory \
 && useradd --system --uid 10001 --gid inventory --no-create-home --shell /usr/sbin/nologin inventory
WORKDIR /app

# Layered jar: dependencies change rarely, the application layer on every build.
COPY --from=build /extracted/dependencies/ ./
COPY --from=build /extracted/spring-boot-loader/ ./
COPY --from=build /extracted/snapshot-dependencies/ ./
COPY --from=build /extracted/application/ ./

USER 10001:10001
# 9090 gRPC (inventory-net only), 8081 Actuator (healthcheck, never published).
EXPOSE 9090 8081
# Exec form: the JVM is PID 1 and receives SIGTERM for the graceful shutdown.
ENTRYPOINT ["java", "-XX:MaxRAMPercentage=75", "-XX:+ExitOnOutOfMemoryError", "-jar", "inventory.jar"]
