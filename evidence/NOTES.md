# Evidence provenance and exclusions

The two `.txt` files are transcriptions of terminal output supplied during the research session. This archive build did not have the original PHP 8.4.14 binary or `nghttpd` available, so the reported crash was not independently re-run while packaging.

The later `push_recovery.php` attempt is deliberately excluded from positive or negative evidence. The listener was stopped: `curl` returned `Connection refused`, and the recovery program reported `HTTP_CODE=0` without invoking callback B. That attempt does not establish callback recovery.

Before publication as a vulnerability report, repeat the control and invalid mode with the listener visibly active, record `php -i`, `curl_version()`, `nghttpd --version`, a source commit/build ID, and test a current supported patch release. Do not infer use-after-free without a suitable memory-safety trace.
