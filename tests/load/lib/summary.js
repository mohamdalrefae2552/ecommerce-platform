// Plain-text end-of-test summary without remote jslib imports (works offline).
// Each script passes its own lines; thresholds decide the exit code.

// failedThresholds(data): names of every metric whose threshold failed.
export function failedThresholds(data) {
  const failed = [];
  for (const [name, metric] of Object.entries(data.metrics)) {
    for (const [expression, result] of Object.entries(metric.thresholds || {})) {
      if (!result.ok) failed.push(`${name}: ${expression}`);
    }
  }
  return failed;
}

// counter(data, name): value of a Counter metric (0 when it never fired).
export function counter(data, name) {
  const metric = data.metrics[name];
  return metric ? metric.values.count : 0;
}

// report(title, lines, data): the text written to stdout by handleSummary.
export function report(title, lines, data) {
  const failed = failedThresholds(data);
  const verdict = failed.length === 0 ? 'PASS' : 'FAIL';
  const body = [`\n=== ${title} ===`, ...lines.map((line) => `  ${line}`)];
  if (failed.length > 0) body.push('  failed thresholds:', ...failed.map((f) => `    - ${f}`));
  body.push(`  result: ${verdict}\n`);
  return body.join('\n');
}
