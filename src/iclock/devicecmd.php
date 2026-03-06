<?php
/**
 * ADMS Protocol — Command acknowledgement endpoint
 *
 * The device posts here after it has executed a command we sent via getrequest.
 * We don't issue commands currently, so this just returns OK.
 */
declare(strict_types=1);

header('Content-Type: text/plain');
echo "OK";
