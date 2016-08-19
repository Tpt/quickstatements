<?php

namespace QuickStatements\Parser;

use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementFilter;

/**
 * @licence GNU GPL v2+
 */
class MainSnakStatementFilter implements StatementFilter {

	/**
	 * @var Snak
	 */
	private $snak;

	public function __construct( Snak $snak ) {
		$this->snak = $snak;
	}

	public function statementMatches( Statement $statement ) {
		return $statement->getMainSnak()->equals( $this->snak );
	}
}
