#!/usr/bin/env bash
# CPU and memory of the stack while the measured part of `make perf-test`
# runs (requirement 3: "what happened in the system during the test").
# Samples `docker stats` every ~2 s until the file named by $STOP exists,
# then prints, per container, the average and peak CPU and the peak memory.
# CPU is a percentage of ONE core (200% = two cores busy).
#   PROJECT=... STOP=<file> perf-stats.sh > <out>
set -euo pipefail

: "${PROJECT:?}" "${STOP:?}"
samples="$(mktemp)"
trap 'rm -f "$samples"' EXIT

while [[ ! -e "$STOP" ]]; do
  docker stats --no-stream --format '{{.Name}} {{.CPUPerc}} {{.MemUsage}}' \
    | grep "^${PROJECT}-" | sed "s/^${PROJECT}-//; s/-1 / /" >>"$samples" || true
  sleep 1
done

awk '
  # Memory such as "123.4MiB" or "1.2GiB" -> MiB.
  function mib(v) { if (v ~ /GiB$/) return v * 1024; if (v ~ /KiB$/) return v / 1024; return v + 0 }
  $1 ~ /k6-run/ { next }
  { cpu = $2; sub(/%/, "", cpu); cpu += 0; m = mib($3)
    sum[$1] += cpu; cnt[$1]++; if (cpu > peak[$1]) peak[$1] = cpu; if (m > peakm[$1]) peakm[$1] = m }
  END { for (n in sum) printf "%-14s %10.1f %10.1f %10.0f %8d\n", n, sum[n] / cnt[n], peak[n], peakm[n], cnt[n] }
' "$samples" | sort -k2 -nr | { printf "%-14s %10s %10s %10s %8s\n" container "avg CPU%" "peak CPU%" "peak MiB" samples; cat; }
