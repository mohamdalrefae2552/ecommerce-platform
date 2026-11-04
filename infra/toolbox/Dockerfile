# Diagnostics and demo helpers (profile `tools`): grpcurl, psql, curl, jq.
# Not part of the application; attached to both networks only for inspection.
FROM fullstorydev/grpcurl:v1.9.3-alpine AS grpcurl

FROM alpine:3.24
RUN apk add --no-cache bash curl jq postgresql18-client ca-certificates
COPY --from=grpcurl /bin/grpcurl /usr/local/bin/grpcurl
RUN adduser -D -u 10001 toolbox
USER toolbox
ENTRYPOINT []
CMD ["bash"]
