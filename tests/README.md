# Tests

No framework, no Composer. A stub WordPress surface in `stubs.php`, real Divi
markup in `fixtures.php`, and seven suites that execute the plugin's logic
directly.

    php tests/run.php    # cache keys, context matching, video and embed rewriting
    php tests/run2.php   # pipeline helpers, settings, safe mode, history, Divi map
    php tests/run3.php   # container wiring, media rewriting, script delay, optimizer
    php tests/run4.php   # host detection, server rules, error attribution
    php tests/run5.php   # adversarial markup, pathological input, builder protection
    php tests/run6.php   # failure guards and the cache store on a real filesystem
    php tests/run7.php   # config as code, fonts, embeds

Run them all:

    for f in tests/run*.php; do php "$f" | tail -1; done

Exit status is non-zero on failure, so this drops straight into CI.
