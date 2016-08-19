<?php

namespace QuickStatements\Parser;

use Exception;

/**
 * @licence GNU GPL v2+
 */
class ParserException extends Exception {

	public function __construct( $message, Exception $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}
}
