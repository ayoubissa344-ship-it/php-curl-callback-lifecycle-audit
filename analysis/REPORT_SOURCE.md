# Looking Beyond the Patch: PHP cURL Push-Callback Replacement Lifecycle Audit

**Research status:** crash candidate; security impact unconfirmed  
**Observed environment:** PHP 8.4.14 NTS, libcurl 8.14.1, HTTP/2 enabled, Alpine Linux 3.21.9 under proot on Android  
**Prepared:** 27 October 2026

## Executive summary

A local PHP cURL test exhibited a segmentation fault after a valid `CURLMOPT_PUSHFUNCTION` receiver was registered, the receiver's external reference was dropped, a replacement with `null` raised `TypeError`, and the same multi handle was processed against a local HTTP/2 Server Push fixture. In the failing transcript, no userland callback invocation was printed; the timing is consistent with the push path but does not directly identify the faulting instruction. The corresponding valid-callback control reached the callback and exited normally.

This is a concrete process-crash observation, not proof of a use-after-free, memory corruption, code execution, remote exploitability, or a confirmed CVE. The reproduction came from a single reported configuration; a current patched PHP release and an instrumented build have not yet been tested.

## Why lifecycle audits matter

PHP's October 2026 security disclosures are useful context for a method, not evidence of a shared defect. CVE-2026-109618 describes request-startup/OOM cleanup state; CVE-2026-109619 describes stale worker-global state surviving beyond the request-owned memory lifetime; CVE-2026-115560 concerns a narrow session upload-progress/serialization configuration. These reports reinforce a general rule: state must not outlive the resource that makes it valid, and exceptional paths must leave state coherent.

The cURL candidate concerns a different lifecycle boundary: replacing a callback stored in a Zend callable cache while a native libcurl callback may remain registered. It is a related *audit pattern*, not the same vulnerability class by established evidence.

## Reproduction sequence

The test fixture uses a local cleartext HTTP/2 server bound to loopback. The server maps `/index.html` to `/push.txt` as a pushed resource. A PHP multi handle enables multiplexing and registers a method callback on an object. A `WeakReference` records when the callback receiver ceases to be reachable from PHP userland.

### Control

1. Register the valid callback.
2. Drop the external reference to the receiver.
3. Add a cURL easy handle for the local HTTP/2 endpoint.
4. Process the multi handle.
5. Observe `CALLBACK_INVOKED`, successful multi status, and process exit 0.

### Invalid replacement

The same sequence is followed, except that after dropping the external reference the script calls `curl_multi_setopt($multi, CURLMOPT_PUSHFUNCTION, null)` and catches the resulting `TypeError`. In the reported run, the receiver destructor ran, the weak reference no longer resolved, `curl_multi_add_handle()` returned success (0), and the process then terminated with `Segmentation fault` / exit 139.

The recovery attempt performed after the listener had stopped is excluded: the connection was refused and HTTP status remained 0. It does not test whether registering a replacement callback restores the native/PHP state.

## Source-level hypothesis

The current `php-src` `ext/curl/multi.c` source shows this ordering in the `CURLMOPT_PUSHFUNCTION` branch:

1. If the old callable cache is initialized, destroy it.
2. Validate the new callback.
3. On invalid input, raise a type error and return before the libcurl setter call.
4. On valid input, add a reference to the callable cache, register `_php_server_push_callback` with libcurl, and set push data.

In the current source, `_php_server_push_callback()` invokes `zend_call_known_fcc()` on `mh->handlers.server_push`. The ordering is consistent with a potentially inconsistent state after failed replacement: the PHP-side callable cache may have been destroyed, while libcurl may still hold the native trampoline. If a push then invokes that trampoline, the stored callable cache is used.

This is a source-level hypothesis. The exact source revision corresponding to the tested PHP 8.4.14 binary was not captured, and no ASan trace or core dump was available. A segmentation fault alone cannot determine whether the failure is an invalid callable invocation, a use-after-free, or another memory-safety problem.

Source: https://github.com/php/php-src/blob/master/ext/curl/multi.c#L2038-L2202

## Evidence summary

| Observation | Result | What it supports |
|---|---|---|
| Valid callback control | Callback invoked, exit 0 | Push path can be reached in the recorded setup |
| Invalid callback replacement | `TypeError`, receiver destroyed, later SIGSEGV / exit 139 | Crash correlated with the failed replacement sequence |
| Recovery attempt while server unavailable | Connection refused, HTTP code 0, no callback B | Excluded; it does not prove recovery or failure |
| Current patched PHP release | Not tested | Affected-version range and present-day status remain unknown |
| ASan / debug trace | Not obtained | Memory-safety classification remains unknown |

## Threat model and impact boundary

The crash sequence requires PHP application code to call the setter with an invalid callback value and then continue using the same multi handle, while a server-push event reaches the still-configured native callback. This is not, by itself, evidence that arbitrary remote users can invoke the invalid setter. Remote reachability depends on how an application constructs callback values, catches exceptions, reuses handles, and selects outbound URLs or peers.

The only currently supported impact statement is: **process crash in a locally scripted sequence on one reported configuration**. No remote denial of service, confidentiality impact, integrity impact, RCE, or critical severity is claimed.

## Historical context and novelty caveat

PHP previously addressed a crash associated with an invalid `CURLMOPT_PUSHFUNCTION` callback. A related issue also reports `CURLMOPT_PUSHFUNCTION` instability on Ubuntu 24.04/libcurl 8.5.0. See:

- https://github.com/php/php-src/search?q=CURLMOPT_PUSHFUNCTION&type=commits
- https://github.com/php/php-src/issues/16723

This makes a novelty check essential. The specific sequence in this report is valid callback -> invalid replacement -> external exception handling -> later Server Push. It should be compared against existing fixes and regression tests before being presented as a new defect.

## Proposed regression and hardening direction

A conservative design is transactional replacement: validate and initialize a temporary callable cache first; only after validation succeeds should the implementation destroy the old cache and install the new one. The native libcurl callback state should remain explicitly consistent with the PHP-side cache. If invalid input is intended to clear the callback, the implementation should instead unregister or neutralize the native callback safely and reset the PHP-side state coherently.

This is a design proposal, not a verified patch. Any patch must account for callable references, failed native `curl_multi_setopt()` calls, handle cleanup, repeated replacement, and PHP's expected API semantics.

## Next validation steps

1. Re-run control and invalid mode with the HTTP/2 server active and capture server logs contemporaneously.
2. Record exact PHP source commit/build metadata, `php -i`, `curl_version()`, and `nghttpd --version`.
3. Repeat on a current supported PHP patch release, currently including PHP 8.4.27 as listed in the October 2026 security release notes.
4. Build PHP/cURL with ASan or comparable instrumentation and determine the fault class.
5. Minimize the sequence and add a php-src PHPT regression test.
6. Submit privately through the project's current security reporting process before claiming a CVE; ask maintainers to check prior art and affected branches.

## Related official PHP security context (October 2026)

- CVE-2026-109618, RCE via OOM corruption: https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w
- CVE-2026-109619, RCE via stale global state: https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp
- CVE-2026-115560, informational session upload-progress advisory: https://www.php.net/archive/2026.php#2026-10-23-1
- PHP 2026 archive and security release information: https://www.php.net/archive/2026.php

These disclosures motivate residual-risk analysis after a fix. They are not presented as technically connected to the cURL candidate.

## Conclusion

The useful contribution today is a small test harness, a control-vs-invalid transcript, and a falsifiable source-level hypothesis. That is enough to invite a focused maintainer review. It is not enough to claim RCE or Critical severity. The next milestone is independent reproduction on supported builds plus a memory-safety trace and a realistic reachability analysis.
