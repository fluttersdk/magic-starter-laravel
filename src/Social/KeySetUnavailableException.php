<?php

namespace FlutterSdk\MagicStarter\Social;

use RuntimeException;

/**
 * A provider answered its key set request without a key set.
 *
 * That is an outage on the provider's side, like a refused connection, and says
 * nothing about the token being verified; a caller answers it as "try again",
 * never as an invalid identity.
 */
class KeySetUnavailableException extends RuntimeException {}
