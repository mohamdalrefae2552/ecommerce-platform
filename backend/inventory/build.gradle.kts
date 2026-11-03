import net.ltgt.gradle.errorprone.errorprone
import org.springframework.boot.gradle.plugin.SpringBootPlugin

plugins {
    java
    jacoco
    alias(libs.plugins.spring.boot)
    alias(libs.plugins.protobuf)
    alias(libs.plugins.spotless)
    alias(libs.plugins.errorprone)
}

group = "com.ecommerce"

java {
    toolchain {
        languageVersion = JavaLanguageVersion.of(25)
    }
}

// The proto contract is owned by the Lead and read in place (docs/03 section 1):
// ../../contracts/proto locally and in the test container, the same relative
// path inside the Docker build (the Dockerfile copies contracts next to us).
sourceSets {
    main {
        proto {
            srcDir("../../contracts/proto")
        }
    }
}

// Declaring the "grpc" plugin is enough: the Spring Boot plugin sets the protoc
// and protoc-gen-grpc-java artifacts, aligned with protobuf-java and grpc-java
// from the BOM, and adds the plugin to every generateProto task.
protobuf {
    plugins {
        create("grpc")
    }
}

dependencies {
    implementation(platform(SpringBootPlugin.BOM_COORDINATES))
    implementation(libs.spring.boot.starter.grpc.server)
    implementation(libs.spring.boot.starter.jdbc)
    implementation(libs.spring.boot.starter.flyway)
    implementation(libs.spring.boot.starter.actuator)
    implementation(libs.spring.boot.starter.webmvc)
    runtimeOnly(libs.flyway.database.postgresql)
    runtimeOnly(libs.postgresql)

    testImplementation(platform(libs.testcontainers.bom))
    testImplementation(libs.spring.boot.starter.test)
    testImplementation(libs.spring.boot.starter.grpc.server.test)
    testImplementation(libs.spring.boot.testcontainers)
    testImplementation(libs.testcontainers.junit.jupiter)
    testImplementation(libs.testcontainers.postgresql)
    testRuntimeOnly(libs.junit.platform.launcher)

    errorprone(libs.errorprone.core)
}

tasks.withType<JavaCompile>().configureEach {
    options.encoding = "UTF-8"
    options.release = 25
    // -Werror also turns every ErrorProne warning into a build failure.
    options.compilerArgs.addAll(listOf("-Xlint:all", "-Werror"))
    options.errorprone {
        disableWarningsInGeneratedCode = true
        excludedPaths = ".*/build/generated/.*"
    }
}

spotless {
    java {
        target("src/**/*.java")
        googleJavaFormat(libs.versions.google.java.format.get())
        removeUnusedImports()
        trimTrailingWhitespace()
        endWithNewline()
    }
}

tasks.jar {
    enabled = false
}

tasks.bootJar {
    archiveFileName = "inventory.jar"
}

tasks.test {
    useJUnitPlatform()
    finalizedBy(tasks.jacocoTestReport)
}

jacoco {
    toolVersion = libs.versions.jacoco.get()
}

// Coverage gate (docs/06): >= 85% of lines in the application and persistence
// layers. It applies as soon as those packages contain code; adapters, the
// domain and generated classes are measured by their own tests, not gated here.
val gatedCoverage =
    sourceSets.main.get().output.classesDirs.asFileTree.matching {
        include("com/ecommerce/inventory/application/**", "com/ecommerce/inventory/persistence/**")
    }

tasks.jacocoTestReport {
    dependsOn(tasks.test)
    reports {
        xml.required = true
        html.required = true
    }
}

tasks.jacocoTestCoverageVerification {
    dependsOn(tasks.test)
    classDirectories.setFrom(gatedCoverage)
    violationRules {
        rule {
            limit {
                counter = "LINE"
                minimum = "0.85".toBigDecimal()
            }
        }
    }
}

tasks.check {
    dependsOn(tasks.jacocoTestCoverageVerification)
}
