<?php

namespace QuickStatements\Parser;

use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Services\Lookup\InMemoryEntityLookup;

class TsvParserTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @dataProvider parserData
	 */
	public function testParse( array $expected, $input ) {
		$entityLookup = new InMemoryEntityLookup( [
			$this->getQ42()
		] );
		$parser = new TsvParser( $entityLookup );
		$this->assertEquals( $expected, $parser->parse( $input ) );
	}

	public function parseData() {
		return [
		];
	}

	private function getQ42() {
		return new Item( new ItemId( 'Q42' ) );
	}
}
