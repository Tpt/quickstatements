<?php

namespace QuickStatements\Parser;

use DataValues\DecimalValue;
use DataValues\Geo\Parsers\GlobeCoordinateParser;
use DataValues\MonolingualTextValue;
use DataValues\QuantityValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use OutOfBoundsException;
use ValueParsers\ParseException;
use Wikibase\DataModel\Entity\BasicEntityIdParser;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\EntityIdParsingException;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\Services\Lookup\EntityLookup;
use Wikibase\DataModel\Services\Lookup\EntityLookupException;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementListProvider;
use Wikibase\DataModel\Term\FingerprintProvider;

/**
 * @licence GNU GPL v2+
 */
class TsvParser {

	const LANGUAGE_CODE_PATTERN = '[a-z]{2,3}'; // TODO: improve

	/**
	 * @var EntityLookup
	 */
	private $entityLookup;

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @var GlobeCoordinateParser
	 */
	private $globeCoordinateParser;

	/**
	 * @var EntityDocument[]
	 */
	private $entities;

	/**
	 * @var EntityDocument|null
	 */
	private $lastEntity;

	public function __construct( EntityLookup $entityLookup ) {
		$this->entityLookup = $entityLookup;
		$this->entityIdParser = new BasicEntityIdParser();
		$this->globeCoordinateParser = new GlobeCoordinateParser();
	}

	public function parse( $file, $delimiter = '\t' ) {
		$this->entities = [];
		$this->lastEntity = null;

		foreach ( explode( "\n", $file ) as $line ) {
			$this->parseLine( explode( $delimiter, $line ) );
		}

		return $this->entities;
	}

	private function parseLine( array $parts ) {
		if ( empty( $parts ) ) {
			return; // TODO: throw an error?
		}

		// TODO: MERGE
		if ( $parts[0] === 'CREATE' ) {
			if ( count( $parts ) !== 1 ) {
				throw new ParserException( "CREATE lines should only contains the keyword CREATE" );
			}
			$this->createItem();
		} elseif ( $parts[0] === 'LAST' || preg_match( ItemId::PATTERN, $parts[0] ) || preg_match( PropertyId::PATTERN, $parts[0] ) ) { // TODO: other entity types?
			if ( count( $parts ) % 2 != 1 ) {
				throw new ParserException( "Statement lines should contains an odd number of cells" );
			}
			$entity = $this->getEntityForEntityId( $parts[0] === 'LAST' ? null : $this->parseEntityId( $parts[0] ) );

			switch ( $parts[1][0] ) {
				case 'L':
					if ( !( $entity instanceof FingerprintProvider ) ) {
						throw new ParserException( "You could only add labels to an entity with labels" );
					}
					$fingerprint = $entity->getFingerprint();
					$languageCode = $this->extractLanguageFromLanguageBaseProperty( $parts[1], 'L' );
					$label = $this->parseString( $parts[2] );
					try {
						if ( $fingerprint->getLabel( $languageCode )->getText() !== $label ) {
							throw new ParserException( "A label already exists in $languageCode for {$entity->getId()}" );
						}
					} catch ( OutOfBoundsException $e ) {
						$fingerprint->setLabel( $languageCode, $label );
					}
					break;
				case 'A':
					if ( !( $entity instanceof FingerprintProvider ) ) {
						throw new ParserException( "You could only add aliases to an entity with aliases" );
					}
					$fingerprint = $entity->getFingerprint();
					$languageCode = $this->extractLanguageFromLanguageBaseProperty( $parts[1], 'A' );
					$alias = $this->parseString( $parts[2] );
					try {
						$aliases = $fingerprint->getAliasGroup( $languageCode )->getAliases();
						if ( !in_array( $alias, $aliases ) ) {
							$aliases[] = $alias;
							$fingerprint->setAliasGroup( $languageCode, $aliases );
						}
					} catch ( OutOfBoundsException $e ) {
						$fingerprint->setAliasGroup( $languageCode, [ $alias ] );
					}
					break;
				case 'D':
					if ( !( $entity instanceof FingerprintProvider ) ) {
						throw new ParserException( "You could only add descriptions to an entity with descriptions" );
					}
					$fingerprint = $entity->getFingerprint();
					$languageCode = $this->extractLanguageFromLanguageBaseProperty( $parts[1], 'L' );
					$description = $this->parseString( $parts[2] );
					try {
						if ( $fingerprint->getDescription( $languageCode )->getText() !== $description ) {
							throw new ParserException( "A description already exists in $languageCode for {$entity->getId()}" );
						}
					} catch ( OutOfBoundsException $e ) {
						$fingerprint->setDescription( $languageCode, $description );
					}
					break;
				case 'S':
					if ( !( $entity instanceof Item ) ) {
						throw new ParserException( "You could only add sitelinks to an item" );
					}
					$siteLinkList = $entity->getSiteLinkList();
					$site = $this->extractSitelinkFromSitelinkBaseProperty( $parts[1], 'S' );
					$page = $this->parseString( $parts[2] );
					try {
						if ( $siteLinkList->getBySiteId( $site )->getPageName() !== $page ) {
							throw new ParserException( "A sitelink to $site already exists in {$entity->getId()}" );
						}
					} catch ( OutOfBoundsException $e ) {
						$siteLinkList->addNewSiteLink( $site, $page );
					}
					break;
				case 'P':
					if ( !( $entity instanceof StatementListProvider ) ) {
						throw new ParserException( "You could only add statements to an entity with statements" );
					}
					$statementsList = $entity->getStatements();
					$mainProperty = $this->parsePropertyId( $parts[1] );
					$mainValue = $this->parseDataValue( $parts[2] );
					$mainSnak = new PropertyValueSnak( $mainProperty, $mainValue );
					$statementsWithSameMainSnak = $statementsList->filter( new MainSnakStatementFilter( $mainSnak ) );

					if ( $statementsWithSameMainSnak->isEmpty() ) {
						$editedStatement = new Statement( $mainSnak );
						$statementsList->addStatement( $editedStatement );
					} elseif ( $statementsWithSameMainSnak->count() === 1 ) {
						$editedStatement = $statementsWithSameMainSnak->toArray()[0];
					} else {
						throw new ParserException( "Found multiple statements to add data to with property $mainProperty and value $mainValue in entity {$entity->getId()}" );
					}
					$reference = new Reference();
					for ( $i = 3; $i < count( $parts ); $i += 2 ) {
						switch ( $parts[$i][0] ) {
							case 'P':
								$editedStatement->getQualifiers()->addSnak( new PropertyValueSnak(
									$this->parsePropertyId( $parts[$i] ),
									$this->parseDataValue( $parts[$i + 1 ] )
								) );
								break;
							case 'S':
								$parts[$i][0] = 'P';
								$reference->getSnaks()->addSnak( new PropertyValueSnak(
									$this->parsePropertyId( $parts[$i] ),
									$this->parseDataValue( $parts[$i + 1 ] )
								) );
								break;
							default:
								throw new ParserException( "Unknown property {$parts[$i]}" );
						}
					}
					if ( !$reference->isEmpty() ) {
						$editedStatement->getReferences()->addReference( $reference ); // TODO: what if a reference with a subset of the snaks aready exists
						// TODO: check if this does not insert a duplicate
					}
					break;
				default:
					throw new ParserException( "Unknown property {$parts[1]}" );
			}
		} else {
			throw new ParserException( "Unknown first cell {$parts[0]}" );
		}
	}

	private function getEntityForEntityId( EntityId $entityId = null ) {
		if ( $entityId === null ) {
			if ( $this->lastEntity === null ) {
				throw new ParserException( "You could not use LAST if there is no line before about an entity" );
			}
			return $this->lastEntity;
		} else {
			$entityIdSerialization = $entityId->getSerialization();
			if ( !array_key_exists( $entityIdSerialization, $this->entities ) ) {
				try {
					$this->entities[$entityIdSerialization] = $this->entityLookup->getEntity( $entityId );
				} catch ( EntityLookupException $e ) {
					throw new ParserException( "Entity $entityId does not exists" );
				}
			}
			$this->lastEntity = $this->entities[$entityIdSerialization];
			return $this->lastEntity;
		}
	}

	private function createItem() {
		$this->lastEntity = new Item();
		$this->entities[] = $this->lastEntity;
		return $this->lastEntity;
	}

	private function parseEntityId( $id ) {
		try {
			return $this->entityIdParser->parse( $id );
		} catch ( EntityIdParsingException $e ) {
			throw new ParserException( $e->getMessage(), $e );
		}
	}

	private function parsePropertyId( $id ) {
		$entityId = $this->parseEntityId( $id );
		if ( !( $entityId instanceof PropertyId ) ) {
			throw new ParserException( "$id should be a valid property ID" );
		}
		return $entityId;
	}

	private function parseDataValue( $value ) {
		if ( preg_match( ItemId::PATTERN, $value ) || preg_match( ItemId::PATTERN, $value ) ) {
			return new EntityIdValue( $this->parseEntityId( $value ) );
		} elseif ( preg_match( '/^"(.*)"$/', $value, $m ) ) {
			return new StringValue( $m[1] );
		} elseif ( preg_match( '/^"(.*)"@([a-z]{2,3})$/', $value, $m ) ) { // TODO: better pattern?
			return new MonolingualTextValue( $m[1], $m[2] );
		} elseif ( preg_match( '/^([-+]\d{1,16}-\d\d-\d\dT\d\d:\d\d:\d\dZ)\/(\d+)$/', $value, $m ) ) {
			return new TimeValue( $m[1], 0, 0, 0, (int) $m[2], TimeValue::CALENDAR_GREGORIAN );
		} elseif ( preg_match( '/^@([+-]?\d+(?:\.\d+)?)\/([+-]?\d+(?:\.\d+)?)$/', $value, $m ) ) {
			try {
				return $this->globeCoordinateParser->parse( $m[1] . ' ' . $m[2] );
			} catch ( ParseException $e ) {
				throw new ParserException( $e->getMessage(), $e );
			}
		} elseif ( preg_match( DecimalValue::QUANTITY_VALUE_PATTERN, $value, $m ) ) {
			$val = new DecimalValue( $value );
			return new QuantityValue( $val, '1', $val, $val );
		} else {
			throw new ParserException( "Unknown value $value" );
		}
	}

	private function parseString( $value ) {
		if ( preg_match( '/^"(.*)"$/', $value, $m ) ) {
			return $m[1];
		} else {
			throw new ParserException( "$value is not a valid string" );
		}
	}

	private function extractLanguageFromLanguageBaseProperty( $property, $prefix ) {
		if ( preg_match( '/^' . $prefix . '(' . self::LANGUAGE_CODE_PATTERN . ')$/', $property, $m ) ) {
			return $m[1];
		} else {
			throw new ParserException( "Invalid special property $property" );
		}
	}

	private function extractSitelinkFromSitelinkBaseProperty( $property, $prefix ) {
		if ( preg_match( '/^' . $prefix . '([a-z]+wiki)$/', $property, $m ) ) { // TODO: match all
			return $m[1];
		} else {
			throw new ParserException( "Invalid special property $property" );
		}
	}
}
