package com.ecommerce.inventory;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.Callable;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;

/**
 * Runs calls on their own threads and releases them together with one start latch, so they hit the
 * service at the same moment.
 */
public final class ConcurrentCalls {

  private ConcurrentCalls() {}

  public static <T> List<T> runSimultaneously(List<Callable<T>> calls) throws Exception {
    CountDownLatch ready = new CountDownLatch(calls.size());
    CountDownLatch start = new CountDownLatch(1);
    ExecutorService threads = Executors.newFixedThreadPool(calls.size());
    try {
      List<Future<T>> futures = new ArrayList<>();
      for (Callable<T> call : calls) {
        futures.add(
            threads.submit(
                () -> {
                  ready.countDown();
                  start.await();
                  return call.call();
                }));
      }
      if (!ready.await(30, TimeUnit.SECONDS)) {
        throw new IllegalStateException("callers did not get ready in time");
      }
      start.countDown();
      // Wait for every call before failing, so no call is still running in the next test.
      List<T> results = new ArrayList<>();
      ExecutionException firstFailure = null;
      for (Future<T> future : futures) {
        try {
          results.add(future.get(60, TimeUnit.SECONDS));
        } catch (ExecutionException e) {
          firstFailure = firstFailure == null ? e : firstFailure;
        }
      }
      if (firstFailure != null) {
        throw firstFailure;
      }
      return results;
    } finally {
      threads.shutdownNow();
    }
  }
}
