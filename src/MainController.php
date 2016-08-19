<?php

namespace QuickStatements;

use Mediawiki\Api\MediawikiApi;
use Mediawiki\DataModel\EditInfo;
use QuickStatements\OAuth\MediaWikiOAuth;
use QuickStatements\OAuth\Token\ConsumerToken;
use QuickStatements\Parser\ParserException;
use QuickStatements\Parser\TsvParser;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Wikibase\Api\WikibaseFactory;

/**
 * Main controller
 *
 * @licence GNU GPL v2+
 */
class MainController {

	const WIKIDATA_API_URI = 'https://www.wikidata.org/w/api.php';

	/**
	 * @var Application
	 */
	private $app;

	/**
	 * @var WikibaseFactory
	 */
	private $wikibaseFactory;

	public function __construct( Application $app, array $config ) {
		$this->app = $app;

		$dataValueSerializationFactory = new DataValueSerializationFactory();
		$this->wikibaseFactory = new WikibaseFactory(
			$this->buildMediawikiApi( $config ),
			$dataValueSerializationFactory->newDataValueDeserializer(),
			$dataValueSerializationFactory->newDataValueSerializer()
		);
	}

	private function buildMediawikiApi( array $config ) {
	    if ( $this->app['session']->has( 'access_token' ) ) {
			$oAuth = new MediaWikiOAuth(
			    OAuthController::OAUTH_URL,
				new ConsumerToken( $config['consumerKey'], $config['consumerSecret'] )
			);
			return $oAuth->buildMediawikiApiFromToken( self::WIKIDATA_API_URI, $this->app['session']->get( 'access_token' ) );
		   } else {
		    return MediawikiApi::newFromApiEndpoint( self::WIKIDATA_API_URI );
		   }
	}

	public function init( Request $request ) {
		return $this->outputsFormTemplate( [] );
	}

	public function save( Request $request ) {
		$tsv = $request->get( 'tsv', '' );
		$parser = new TsvParser( $this->wikibaseFactory->newEntityLookup() );
		try {
			$result = $parser->parse( $tsv );
		} catch ( ParserException $e ) {
			return $this->outputsFormTemplate( [
				'error' => $e->getMessage()
			] );
		}

		$saver = $this->wikibaseFactory->newEntityDocumentSaver();
		foreach ( $result as $entity ) {
			$saver->save( $entity, new EditInfo( 'TODO' ) ); // TODO
		}
		return $this->outputsFormTemplate( [
			'success' => 'ok'
		] );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param array $params
	 * @return Response
	 */
	protected function outputsFormTemplate( array $params ) {
		$defaultParams = [];
		$params = array_merge( $defaultParams, $params );
		return $this->outputsTemplate( 'commons/form.twig', $params );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param $templateName
	 * @param array $params
	 * @return Response
	 */
	protected function outputsTemplate( $templateName, array $params ) {
		$defaultParams = [
			'success' => '',
			'warning' => '',
			'error' => '',
			'user' => $this->app['session']->get( 'user' )
		];
		$params = array_merge( $defaultParams, $params );
		return $this->app['twig']->render( $templateName, $params );
	}
}
