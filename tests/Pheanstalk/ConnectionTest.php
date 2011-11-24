<?php

/**
 * Tests for the \Pheanstalk\Connection.
 * Relies on a running beanstalkd server.
 *
 * @author Paul Annesley
 * @package Pheanstalk
 * @licence http://www.opensource.org/licenses/mit-license.php
 */
class Pheanstalk_ConnectionTest
	extends UnitTestCase
{
	const SERVER_HOST = 'localhost';
	const SERVER_PORT = '11300';
	const CONNECT_TIMEOUT = 2;

	public function testConnectionFailsToIncorrectPort()
	{
		$connection = new \Pheanstalk\Connection(
			self::SERVER_HOST,
			self::SERVER_PORT + 1
		);

		$command = new \Pheanstalk\Command\UseCommand('test');
		$this->expectException('\Pheanstalk\Exception\ConnectionException');
		$connection->dispatchCommand($command);
	}

	public function testDispatchCommandSuccessful()
	{
		$connection = new \Pheanstalk\Connection(
			self::SERVER_HOST,
			self::SERVER_PORT
		);

		$command = new \Pheanstalk\Command\UseCommand('test');
		$response = $connection->dispatchCommand($command);

		$this->assertIsA($response, '\Pheanstalk\Response');
	}

	public function testConnectionResetIfSocketExceptionIsThrown()
	{
		$pheanstalk = new \Pheanstalk\Pheanstalk(
			self::SERVER_HOST,
			self::SERVER_PORT,
			self::CONNECT_TIMEOUT
		);

		Mock::generate('Pheanstalk\Connection', 'MockPheanstalk_Connection');
		$connection = new MockPheanstalk_Connection('');
		$connection->returns('getHost', self::SERVER_HOST);
		$connection->returns('getPort', self::SERVER_PORT);
		$connection->returns('getConnectTimeout', self::CONNECT_TIMEOUT);
		$connection->throwOn(
			'dispatchCommand',
			new \Pheanstalk\Exception\SocketException('socket error simulated')
		);

		$pheanstalk->putInTube('testconnectionreset', __METHOD__);
		$pheanstalk->watchOnly('testconnectionreset');

		$pheanstalk->setConnection($connection);
		$connection->expectOnce('dispatchCommand');
		$job = $pheanstalk->reserve();

		$this->assertEqual(__METHOD__, $job->getData());
	}

	// ----------------------------------------
	// private

	private function _getConnection()
	{
		return new \Pheanstalk\Connection(self::SERVER_HOST, self::SERVER_PORT);
	}
}

