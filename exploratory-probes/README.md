# Separate exploratory PHPT probes - not confirmed findings

These two tests are retained as research leads for adjacent callback-lifetime paths, not as proof of the `CURLMOPT_PUSHFUNCTION` invalid-replacement crash.

- `curl_multi_push_callback_lifetime.phpt` tests callback self-replacement and receiver access during the push callback.
- `curl_seek_callback_lifetime.phpt` explores `CURLOPT_SEEKFUNCTION`; this API is not present in every supported PHP branch and the test targets a development tree that exposes it.

Run these only in a disposable PHP source checkout using php-src's test harness. They were not executed during this archive build. A failure, crash, or static resemblance must be triaged independently. Do not merge their outcomes into the primary finding without new logs and separate analysis.
