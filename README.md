# PHP cURL Push-Callback Replacement: Lifecycle Audit

**Status:** Reproducible crash candidate observed on one local configuration. Security impact, remote reachability, affected-version range, and novelty are not established.

This repository packages a small local reproducer, a successful control, the observed console transcripts, and a source-level analysis of callback replacement in PHP's cURL extension. It is intended as a review aid for PHP maintainers and security researchers, not as a claim of a confirmed CVE.

## Finding in one paragraph

In a local test on PHP 8.4.14 with libcurl 8.14.1 and HTTP/2 support, registering a valid `CURLMOPT_PUSHFUNCTION` callback, dropping the caller's reference to its receiver, attempting to replace the callback with `null`, and catching the resulting `TypeError`, the process later segfaulted during multi processing against a local HTTP/2 Server Push fixture (exit 139). The failing transcript does not show a userland callback invocation; the timing is consistent with the push path, but does not directly prove which instruction faulted. The matching control, which did not attempt the invalid replacement, invoked the callback and exited successfully. This is a process-crash observation. It does **not** establish use-after-free, arbitrary code execution, remote exploitability, or critical severity.

## Why the sequence is interesting

The current `php-src` `ext/curl/multi.c` source has this ordering in the `CURLMOPT_PUSHFUNCTION` setter:

1. Destroy the existing Zend callable cache, if initialized.
2. Validate the proposed callback.
3. Return on validation failure.
4. Only after successful validation, register the PHP trampoline with libcurl.

The corresponding `_php_server_push_callback()` calls `zend_call_known_fcc()` on the stored callable cache. This control flow is consistent with a possible invalid internal callback state after a failed replacement, while libcurl may still have the previously installed native callback. That is a hypothesis supported by source ordering and a crash trace from one run, not yet a proof of memory corruption.

Source reference: [`ext/curl/multi.c` on php-src master](https://github.com/php/php-src/blob/master/ext/curl/multi.c#L2038-L2202). The exact source revision used to build the reported PHP 8.4.14 binary was not captured, so source-to-binary matching remains a verification item.

## Environment of the reported run

- Alpine Linux 3.21.9 in a proot environment on Android
- PHP 8.4.14 NTS (`php84`)
- libcurl 8.14.1, HTTP/2 enabled
- Local cleartext HTTP/2 test server: `nghttpd`
- Target endpoint: `http://127.0.0.1:8080/index.html`

The results in `evidence/` were transcribed from the research session. They were not independently re-executed while this archive was assembled. The recovery experiment is intentionally **not** claimed as evidence: the server was stopped during that attempt, so no push callback was observed.

## Reproduction

Requirements:

- PHP CLI with the `curl` extension
- PHP/libcurl built with HTTP/2 support and `CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE`
- `nghttpd` from nghttp2, with the `--no-tls`, `--addr`, document-root, and push options shown below

Open a terminal and start the local test server:

```sh
cd repro/server
./run-http2-push.sh
```

Keep that terminal open. In a second terminal, from the repository root:

```sh
php repro/print_environment.php
php repro/push_callback_replacement.php control
```

On the reported Termux/Alpine setup, the CLI command may be named `php84`; in that case, replace `php` with `php84` in these commands.

The control should reach `CALLBACK_INVOKED`, show a successful multi status, and exit normally. The invalid run is expected to raise `TypeError` before the multi request. Its reported crash occurred after `ADD_STATUS=0`; the callback itself was not logged in the failing transcript. Run the candidate in a **separate PHP process**:

```sh
php repro/push_callback_replacement.php invalid
printf 'PROCESS_EXIT=%s\n' "$?"
```

On the reported configuration, the invalid path threw a `TypeError`, destroyed the former receiver, and later segfaulted after the multi handle was added and the push path was exercised. Behavior may differ across PHP/libcurl builds. A missing listener or HTTP status `0` means the test did not establish that Server Push reached the callback path; do not count that as a negative or positive reproduction.

The test server binds to `127.0.0.1` only. Run these tests only on a local development environment you control. A crash test is not a remote exploit demonstration.

## Expected observations

| Mode | Reported result | Interpretation |
| --- | --- | --- |
| `control` | Callback invoked; exit 0 | Confirms the local HTTP/2 push setup can reach the callback path |
| `invalid` | `TypeError`, receiver destruction, then SIGSEGV / exit 139 | Crash candidate correlated with failed callback replacement |
| `recovery` experiment from the session | Not valid: server refused connection; no push observed | Excluded from the evidence set |

## What this does and does not establish

**Established by the reported test:** a process crash occurred in one controlled local sequence, while its control completed successfully.

**Supported by source review:** the old callable cache is destroyed before validation of the replacement; the error path returns before a new callable is installed; the native push callback path later invokes the stored callable cache.

**Not established:** a use-after-free, heap corruption, code execution, remote reachability, exploitation without application-level invalid callback input, affected-version range, patch status in current releases, or a severity score.

An application must first execute the invalid setter call and continue using the same multi handle for the crash sequence to be reached. Whether a realistic application exposes that sequence to an untrusted remote peer is a separate reachability question.

## Prior art and novelty checks

PHP has previously addressed a crash involving an invalid `CURLMOPT_PUSHFUNCTION` callback. See the historical php-src notes and repository issue context before treating this as novel:

- [php-src security/bug-fix history](https://github.com/php/php-src/search?q=CURLMOPT_PUSHFUNCTION&type=commits)
- [php-src issue #16723: push-function issues](https://github.com/php/php-src/issues/16723)

Those references are reasons to check for a duplicate or regression, not proof that this exact sequence is already covered or new. The report should go through the project's current security-reporting process before a vulnerability claim is made.

## Relationship to October 2026 PHP security disclosures

The PDF frames the audit method against PHP's October 2026 disclosures about OOM cleanup and stale worker state. Those issues are in different execution paths. They motivate lifecycle-invariant auditing; they do not establish a shared root cause with this cURL candidate.

- [CVE-2026-109618: RCE via OOM corruption](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w)
- [CVE-2026-109619: RCE via stale global state](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp)
- [CVE-2026-115560: PHP informational advisory on session upload progress](https://www.php.net/archive/2026.php#2026-10-23-1)

These are context only, not a severity analogy. In particular, their critical severity must not be transferred to a callback crash without evidence of comparable impact and reachability.

## Recommended maintainer checks

1. Reproduce with a current supported PHP patch release and record the exact PHP source commit, compiler, libcurl version, HTTP/2 backend, and server output.
2. Add a regression test for valid callback -> invalid replacement -> push, with a successful control.
3. Re-run the test with ASan or an equivalent instrumented build to determine whether the failure is an invalid callable invocation, a memory-safety error, or something else.
4. Test transactional replacement semantics: validate the proposed callback in a temporary callable cache before destroying/replacing the old one. Decide explicitly whether an invalid replacement should preserve the prior callback or unregister the native callback safely.
5. Review adjacent callback setter/caller pairs for the same invariant, while keeping each candidate separate until independently reproduced.

## Files

- `repro/push_callback_replacement.php` - control and invalid-replacement test in one script
- `repro/server/` - local HTTP/2 push fixture
- `evidence/` - reported control/crash transcripts and provenance notes
- `analysis/PHP_CURL_CALLBACK_LIFECYCLE_REPORT.pdf` - research analysis
- `analysis/REPORT_SOURCE.md` - editable report source
- `exploratory-probes/` - distinct PHPT research probes, explicitly marked as unvalidated candidates

## Responsible research note

Do not present this as confirmed RCE or a confirmed CVE. Share the minimal reproduction with maintainers through the project's current security channel, disclose the environment and prior-art links, and ask for confirmation of the affected source range before assigning an identifier or severity.
