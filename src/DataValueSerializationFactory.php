<?php

namespace QuickStatements;

use DataValues\Deserializers\DataValueDeserializer;
use DataValues\Serializers\DataValueSerializer;

/**
 * @licence GNU GPL v2+
 */
class DataValueSerializationFactory {

	public function newDataValueSerializer() {
		return new DataValueSerializer();
	}

	public function newDataValueDeserializer() {
		return new DataValueDeserializer( [
			'number' => 'DataValues\NumberValue',
			'string' => 'DataValues\StringValue',
			'globecoordinate' => 'DataValues\GlobeCoordinateValue',
			'monolingualtext' => 'DataValues\MonolingualTextValue',
			'multilingualtext' => 'DataValues\MultilingualTextValue',
			'quantity' => 'DataValues\QuantityValue',
			'time' => 'DataValues\TimeValue',
			'wikibase-entityid' => 'Wikibase\DataModel\Entity\EntityIdValue'
		] );
	}
}
