package com.ecommerce.inventory.grpc;

import io.grpc.ForwardingServerCall;
import io.grpc.ForwardingServerCallListener;
import io.grpc.Metadata;
import io.grpc.ServerCall;
import io.grpc.ServerCallHandler;
import io.grpc.ServerInterceptor;
import io.grpc.Status;
import java.util.UUID;
import java.util.function.Supplier;
import java.util.regex.Pattern;
import org.slf4j.MDC;
import org.springframework.core.Ordered;
import org.springframework.core.annotation.Order;
import org.springframework.grpc.server.GlobalServerInterceptor;
import org.springframework.stereotype.Component;

/**
 * Correlates every gRPC call with the caller's {@code x-request-id} (docs/03 section 5).
 *
 * <p>The id is put in the logging MDC under {@value #MDC_KEY} for every callback of the call (the
 * callbacks may run on different threads, so the MDC is set and cleared around each of them) and
 * echoed back in the response headers, or in the trailers when the call ends before any header is
 * sent. A missing id, or one that is not a short token of safe characters (log injection), is
 * replaced by a generated UUID.
 */
@Component
@GlobalServerInterceptor
@Order(Ordered.HIGHEST_PRECEDENCE)
public class RequestIdServerInterceptor implements ServerInterceptor {

  /** Metadata key of the request id, sent by the caller and echoed by the server. */
  public static final Metadata.Key<String> REQUEST_ID_HEADER =
      Metadata.Key.of("x-request-id", Metadata.ASCII_STRING_MARSHALLER);

  /** MDC key under which the request id appears in every log line of the call. */
  public static final String MDC_KEY = "request_id";

  private static final Pattern VALID_REQUEST_ID = Pattern.compile("[A-Za-z0-9._:-]{1,128}");

  @Override
  public <ReqT, RespT> ServerCall.Listener<ReqT> interceptCall(
      ServerCall<ReqT, RespT> call, Metadata headers, ServerCallHandler<ReqT, RespT> next) {
    String requestId = resolve(headers.get(REQUEST_ID_HEADER));
    ServerCall<ReqT, RespT> echoing = new EchoingCall<>(call, requestId);
    ServerCall.Listener<ReqT> delegate =
        callWithRequestId(requestId, () -> next.startCall(echoing, headers));
    return new MdcListener<>(delegate, requestId);
  }

  private static <T> T callWithRequestId(String requestId, Supplier<T> action) {
    MDC.put(MDC_KEY, requestId);
    try {
      return action.get();
    } finally {
      MDC.remove(MDC_KEY);
    }
  }

  private static void runWithRequestId(String requestId, Runnable action) {
    MDC.put(MDC_KEY, requestId);
    try {
      action.run();
    } finally {
      MDC.remove(MDC_KEY);
    }
  }

  private static String resolve(String candidate) {
    if (candidate != null && VALID_REQUEST_ID.matcher(candidate).matches()) {
      return candidate;
    }
    return UUID.randomUUID().toString();
  }

  /** Adds the request id to the response headers, or to the trailers of a trailers-only reply. */
  private static final class EchoingCall<ReqT, RespT>
      extends ForwardingServerCall.SimpleForwardingServerCall<ReqT, RespT> {

    private final String requestId;
    private boolean headersSent;

    EchoingCall(ServerCall<ReqT, RespT> delegate, String requestId) {
      super(delegate);
      this.requestId = requestId;
    }

    @Override
    public void sendHeaders(Metadata headers) {
      headers.put(REQUEST_ID_HEADER, requestId);
      headersSent = true;
      super.sendHeaders(headers);
    }

    @Override
    public void close(Status status, Metadata trailers) {
      if (!headersSent) {
        trailers.put(REQUEST_ID_HEADER, requestId);
      }
      super.close(status, trailers);
    }
  }

  /** Sets the request id in the MDC of whichever thread runs each callback, then removes it. */
  private static final class MdcListener<ReqT>
      extends ForwardingServerCallListener.SimpleForwardingServerCallListener<ReqT> {

    private final String requestId;

    MdcListener(ServerCall.Listener<ReqT> delegate, String requestId) {
      super(delegate);
      this.requestId = requestId;
    }

    @Override
    public void onMessage(ReqT message) {
      runWithRequestId(requestId, () -> super.onMessage(message));
    }

    @Override
    public void onHalfClose() {
      runWithRequestId(requestId, () -> super.onHalfClose());
    }

    @Override
    public void onCancel() {
      runWithRequestId(requestId, () -> super.onCancel());
    }

    @Override
    public void onComplete() {
      runWithRequestId(requestId, () -> super.onComplete());
    }

    @Override
    public void onReady() {
      runWithRequestId(requestId, () -> super.onReady());
    }
  }
}
