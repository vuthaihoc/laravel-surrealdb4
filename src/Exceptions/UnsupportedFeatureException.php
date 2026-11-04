<?php

namespace LaravelSurrealDb\Exceptions;

use LogicException;

/**
 * A Laravel feature SurrealDB's PostgreSQL dialect cannot run, and the
 * driver cannot emulate.
 */
class UnsupportedFeatureException extends LogicException {}
