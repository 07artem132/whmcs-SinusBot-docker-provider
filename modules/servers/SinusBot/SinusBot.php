<?php

require ROOTDIR . '/modules/servers/SinusBot/vendor/autoload.php';
require ROOTDIR . '/modules/servers/SinusBot/Helper/SinusBot.php';

use Docker\Docker;
use Docker\DockerClient;
use Docker\API\Model\ContainerConfig;
use Docker\API\Model\PortBinding;
use Docker\API\Model\HostConfig;
use Docker\API\Model\NetworkingConfig;
use SinusBot\Helper\Sinusbot;

function SinusBot_CreateAccount( array $params ) {
	try {
		$client = new DockerClient ( [
			'remote_socket' => "tcp://$params[serverip]:$params[serverport]",
			'ssl'           => false,
		] );

		$docker          = new Docker( $client );
		$ContainerConfig = new ContainerConfig;
		$portBinding     = new PortBinding();
		$allowPortList   = array_flip( range( explode( ':', $params['configoption2'] )[0], explode( ':', $params['configoption2'] )[1] ) );
		foreach ( $docker->getContainerManager()->findAll() as $container ) {
			foreach ( $container->getPorts() as $port ) {
				unset( $allowPortList[ $port->getPublicPort() ] );
			}
		}
		$ContainerPort = (string) array_shift( array_flip( $allowPortList ) );
		$portBinding->setHostPort( $ContainerPort );
		$portBinding->setHostIp( '0.0.0.0' );

		$ContainerConfig->setExposedPorts( [ '8080/tcp' => new \stdClass ] );

		$portMap             = new \ArrayObject();
		$portMap['8080/tcp'] = [ $portBinding ];

		$hostConfig = new HostConfig();
		$hostConfig->setPortBindings( $portMap );


		$ContainerConfig->setHostConfig( $hostConfig );
		$ContainerConfig->setNetworkingConfig();
		$ContainerConfig->setImage( 'sinus/0.9.9-98d0cd5:latest' );
		$containerCreateResult = $docker->getContainerManager()->create( $ContainerConfig, [ 'name' => (string) $ContainerPort ] );
		$docker->getContainerManager()->start( $containerCreateResult->getId() );
		$SinusbotAPI = new Sinusbot( 'http://' . $params['serverip'] . ':' . '9043' );
		$SinusbotAPI->login( 'admin', 'foobar' );
		foreach ( $SinusbotAPI->getUsers() as $item ) {
			if ( $item['username'] == 'admin' ) {
				$passwordAdmin  = bin2hex( openssl_random_pseudo_bytes( 20 ) );
				$passwordClient = bin2hex( openssl_random_pseudo_bytes( 20 ) );
				$SinusbotAPI->setUserPassword( $passwordAdmin, $item['id'] );
				$SinusbotAPI->addUser( 's-v', $passwordClient, 2147483647 );
			}
		}
		$command  = 'UpdateClientProduct';
		$postData = array(
			'serviceid'       => $params['serviceid'],
			'domain'          => 'http://' . $params['serverhostname'] . ':' . $ContainerPort . '/',
			'serviceusername' => 'admin',
			'servicepassword' => $passwordAdmin,
			'customfields'    => base64_encode( serialize( array(
				"port"         => $ContainerPort,
				"container id" => $containerCreateResult->getId(),
				'login'        => 's-v',
				'password'     => $passwordClient
			) ) ),
		);

		localAPI( $command, $postData );
	} catch ( Exception $e ) {
		logModuleCall(
			'provisioningmodule',
			__FUNCTION__,
			$params,
			$e->getMessage(),
			$e->getTraceAsString()
		);

		return $e->getMessage();
	}

	return 'success';
}

function SinusBot_SuspendAccount( array $params ) {
	try {
		$client = new DockerClient ( [
			'remote_socket' => "tcp://$params[serverip]:$params[serverport]",
			'ssl'           => false,
		] );

		$docker = new Docker( $client );
		$docker->getContainerManager()->stop( $params['customfields']['container id'] );

	} catch ( Exception $e ) {
		// Record the error in WHMCS's module log.
		logModuleCall(
			'provisioningmodule',
			__FUNCTION__,
			$params,
			$e->getMessage(),
			$e->getTraceAsString()
		);

		return $e->getMessage();
	}

	return 'success';
}

function SinusBot_UnsuspendAccount( array $params ) {
	try {
		$client = new DockerClient ( [
			'remote_socket' => "tcp://$params[serverip]:$params[serverport]",
			'ssl'           => false,
		] );

		$docker = new Docker( $client );
		$docker->getContainerManager()->start( $params['customfields']['container id'] );
	} catch ( Exception $e ) {
		// Record the error in WHMCS's module log.
		logModuleCall(
			'provisioningmodule',
			__FUNCTION__,
			$params,
			$e->getMessage(),
			$e->getTraceAsString()
		);

		return $e->getMessage();
	}

	return 'success';
}

function SinusBot_TerminateAccount( array $params ) {
	try {
		$client = new DockerClient ( [
			'remote_socket' => "tcp://$params[serverip]:$params[serverport]",
			'ssl'           => false,
		] );

		$docker = new Docker( $client );
		$docker->getContainerManager()->stop( $params['customfields']['container id'] );
		$docker->getContainerManager()->remove( $params['customfields']['container id'] );
	} catch ( Exception $e ) {
		// Record the error in WHMCS's module log.
		logModuleCall(
			'provisioningmodule',
			__FUNCTION__,
			$params,
			$e->getMessage(),
			$e->getTraceAsString()
		);

		return $e->getMessage();
	}

	return 'success';
}

function SinusBot_ClientArea( array $params ) {
	try {
		return array(
			#'tabOverviewReplacementTemplate' => $templateFile,
			#'templateVariables'              => array(
			#	'extraVariable1' => $extraVariable1,
			#	'extraVariable2' => $extraVariable2,
		#	),
		);
	} catch ( Exception $e ) {
		// Record the error in WHMCS's module log.
		logModuleCall(
			'provisioningmodule',
			__FUNCTION__,
			$params,
			$e->getMessage(),
			$e->getTraceAsString()
		);

		// In an error condition, display an error page.
		return array(
		#	'tabOverviewReplacementTemplate' => 'error.tpl',
			'templateVariables'              => array(
				'usefulErrorHelper' => $e->getMessage(),
			),
		);
	}
}


function SinusBot_ConfigOptions() {
	return array(
		'docker image'      => array(
			'Type'        => 'text',
			'Size'        => '30',
			'Default'     => 'sinus/0.9.9-98d0cd5:latest',
			'Description' => '',
		),
		'docker port range' => array(
			'Type'        => 'text',
			'Size'        => '10',
			'Default'     => '9000:9100',
			'Description' => 'Enter docker container port range',
		),

	);
}

function SinusBot_TestConnection( array $params ) {
	try {
		$success  = true;
		$errorMsg = '';

		$client = new DockerClient ( [
			'remote_socket' => "tcp://$params[serverip]:$params[serverport]",
			'ssl'           => false,
		] );

		$docker = new Docker( $client );
		$docker->getContainerManager()->findAll();

	} catch ( Exception $e ) {
		// Record the error in WHMCS's module log.
		logModuleCall(
			'provisioningmodule',
			__FUNCTION__,
			$params,
			$e->getMessage(),
			$e->getTraceAsString()
		);
		$success  = false;
		$errorMsg = $e->getMessage();
	}

	return array(
		'success' => $success,
		'error'   => $errorMsg,
	);
}

function SinusBot_MetaData() {
	return array(
		'DisplayName'       => 'SinusBot Docker',
		'APIVersion'        => '1.1', // Use API Version 1.1
		'RequiresServer'    => true, // Set true if module requires a server to work
		'DefaultNonSSLPort' => '2375', // Default Non-SSL Connection Port
		'DefaultSSLPort'    => '2375', // Default SSL Connection Port
	);
}
