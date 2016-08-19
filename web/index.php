<?php

namespace QuickStatements;

use Silex\Application;
use Silex\Provider\MonologServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set( 'UTC' );

$config = parse_ini_file( __DIR__ . '/../config.ini' );

$app = new Application();
$app->register( new SessionServiceProvider() );
$app->register( new TwigServiceProvider(), [
	'twig.path' => __DIR__ . '/../views',
] );
$app->register( new MonologServiceProvider() );
$app['debug'] = isset( $config['debug'] ) && $config['debug'];

$mainController = new MainController( $app, $config );
$oauthController = new OAuthController( $app, $config );

$app->get( '/', function() use ( $app ) {
	return $app->redirect( 'qs/init' );
} );

$app->get( 'qs/init', function( Request $request ) use ( $mainController ) {
	return $mainController->init( $request );
} )->bind( 'qs-init' );

$app->post( 'qs/save', function( Request $request ) use ( $mainController ) {
	return $mainController->save( $request );
} )->bind( 'qs-save' );

$app->get( 'oauth/init', function( Request $request ) use ( $oauthController ) {
	return $oauthController->init( $request );
} )->bind( 'oauth-init' );

$app->get( 'oauth/callback', function( Request $request ) use ( $oauthController ) {
	return $oauthController->callback( $request );
} )->bind( 'oauth-callback' );

$app->run();
