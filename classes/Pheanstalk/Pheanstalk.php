<?php
namespace Pheanstalk;
use Pheanstalk\Command;
use Pheanstalk\Job;
use Pheanstalk\Response;

/**
 * Pheanstalk is a pure PHP 5.2+ client for the beanstalkd workqueue.
 * The Pheanstalk class is a simple facade for the various underlying components.
 *
 * @see http://github.com/kr/beanstalkd
 * @see http://xph.us/software/beanstalkd/
 *
 * @author Paul Annesley
 * @package Pheanstalk
 * @licence http://www.opensource.org/licenses/mit-license.php
 */
class Pheanstalk
{
	const DEFAULT_PORT = 11300;
	const DEFAULT_DELAY = 0; // no delay
	const DEFAULT_PRIORITY = 1024; // most urgent: 0, least urgent: 4294967295
	const DEFAULT_TTR = 60; // 1 minute

	private $_connection;

	/**
	 * @param string $host
	 * @param int $port
	 */
	public function __construct($host, $port = self::DEFAULT_PORT, $connectTimeout = null)
	{
		$this->setConnection(new \Pheanstalk\Connection($host, $port, $connectTimeout));
	}

	/**
	 * @param \Pheanstalk\Connection
	 * @chainable
	 */
	public function setConnection($connection)
	{
		$this->_connection = $connection;
		return $this;
	}

	// ----------------------------------------

	/**
	 * Puts a job into a 'buried' state, revived only by 'kick' command.
	 *
	 * @param Job $job
	 * @return void
	 */
	public function bury($job, $priority = self::DEFAULT_PRIORITY)
	{
		$this->_dispatch(new Command\BuryCommand($job, $priority));
	}

	/**
	 * Permanently deletes a job.
	 *
	 * @param object $job Job
	 * @chainable
	 */
	public function delete($job)
	{
		$this->_dispatch(new Command\DeleteCommand($job));
		return $this;
	}

	/**
	 * Remove the specified tube from the watchlist
	 *
	 * @param string $tube
	 * @chainable
	 */
	public function ignore($tube)
	{
		$this->_dispatch(new Command\IgnoreCommand($tube));
		return $this;
	}

	/**
	 * Kicks buried or delayed jobs into a 'ready' state.
	 * If there are buried jobs, it will kick up to $max of them.
	 * Otherwise, it will kick up to $max delayed jobs.
	 *
	 * @param int $max The maximum jobs to kick
	 * @return int Number of jobs kicked
	 */
	public function kick($max)
	{
		$response = $this->_dispatch(new Command\KickCommand($max));
		return $response['kicked'];
	}

	/**
	 * The names of all tubes on the server.
	 *
	 * @return array
	 */
	public function listTubes()
	{
		return (array) $this->_dispatch(
			new Command\ListTubesCommand()
		);
	}

	/**
	 * The names of the tubes being watched, to reserve jobs from.
	 *
	 * @return array
	 */
	public function listTubesWatched()
	{
		return (array) $this->_dispatch(
			new Command\ListTubesWatchedCommand()
		);
	}

	/**
	 * The name of the current tube used for publishing jobs to.
	 *
	 * @return string
	 */
	public function listTubeUsed()
	{
		$response = $this->_dispatch(
			new Command\ListTubeUsedCommand()
		);

		return $response['tube'];
	}

	/**
	 * Temporarily prevent jobs being reserved from the given tube.
	 *
	 * @param string $tube The tube to pause
	 * @param int $delay Seconds before jobs may be reserved from this queue.
	 * @chainable
	 */
	public function pauseTube($tube, $delay)
	{
		$this->_dispatch(new Command\PauseTubeCommand($tube, $delay));
		return $this;
	}

	/**
	 * Inspect a job in the system, regardless of what tube it is in.
	 *
	 * @param int $jobId
	 * @return object Job
	 */
	public function peek($jobId)
	{
		$response = $this->_dispatch(
			new Command\PeekCommand($jobId)
		);

		return new Job($response['id'], $response['jobdata']);
	}

	/**
	 * Inspect the next ready job in the currently used tube.
	 *
	 * @return object Job
	 */
	public function peekReady()
	{
		$response = $this->_dispatch(
			new Command\PeekCommand(Command\PeekCommand::TYPE_READY)
		);

		return new Job($response['id'], $response['jobdata']);
	}

	/**
	 * Inspect the shortest-remaining-delayed job in the currently used tube.
	 *
	 * @return object Job
	 */
	public function peekDelayed()
	{
		$response = $this->_dispatch(
			new Command\PeekCommand(Command\PeekCommand::TYPE_DELAYED)
		);

		return new Job($response['id'], $response['jobdata']);
	}

	/**
	 * Inspect the next job in the list of buried jobs of the currently used tube.
	 *
	 * @return object Job
	 */
	public function peekBuried()
	{
		$response = $this->_dispatch(
			new Command\PeekCommand(Command\PeekCommand::TYPE_BURIED)
		);

		return new Job($response['id'], $response['jobdata']);
	}

	/**
	 * Puts a job on the queue.
	 *
	 * @param string $data The job data
	 * @param int $priority From 0 (most urgent) to 0xFFFFFFFF (least urgent)
	 * @param int $delay Seconds to wait before job becomes ready
	 * @param int $ttr Time To Run: seconds a job can be reserved for
	 * @return int The new job ID
	 */
	public function put(
		$data,
		$priority = self::DEFAULT_PRIORITY,
		$delay = self::DEFAULT_DELAY,
		$ttr = self::DEFAULT_TTR
	)
	{
		$response = $this->_dispatch(
			new Command\PutCommand($data, $priority, $delay, $ttr)
		);

		return $response['id'];
	}

	/**
	 * Puts a reserved job back into the ready queue.
	 *
	 * Marks the jobs state as "ready" to be run by any client.
	 * It is normally used when the job fails because of a transitory error.
	 *
	 * @param object $job Job
	 * @param int $priority From 0 (most urgent) to 0xFFFFFFFF (least urgent)
	 * @param int $delay Seconds to wait before job becomes ready
	 * @chainable
	 */
	public function release(
		$job,
		$priority = self::DEFAULT_PRIORITY,
		$delay = self::DEFAULT_DELAY
	)
	{
		$this->_dispatch(
			new Command\ReleaseCommand($job, $priority, $delay)
		);

		return $this;
	}

	/**
	 * Reserves/locks a ready job in a watched tube.
	 *
	 * A non-null timeout uses the 'reserve-with-timeout' instead of 'reserve'.
	 *
	 * A timeout value of 0 will cause the server to immediately return either a
	 * response or TIMED_OUT.  A positive value of timeout will limit the amount of
	 * time the client will block on the reserve request until a job becomes
	 * available.
	 *
	 * @param int $timeout
	 * @return object Job
	 */
	public function reserve($timeout = null)
	{
		$response = $this->_dispatch(
			new Command\ReserveCommand($timeout)
		);

		$falseResponses = array(
			Response::RESPONSE_DEADLINE_SOON,
			Response::RESPONSE_TIMED_OUT,
		);

		if (in_array($response->getResponseName(), $falseResponses))
		{
			return false;
		}
		else
		{
			return new Job($response['id'], $response['jobdata']);
		}
	}

	/**
	 * Gives statistical information about the specified job if it exists.
	 *
	 * @param Job or int $job
	 * @return object
	 */
	public function statsJob($job)
	{
		return $this->_dispatch(new Command\StatsJobCommand($job));
	}

	/**
	 * Gives statistical information about the specified tube if it exists.
	 *
	 * @param string $tube
	 * @return object
	 */
	public function statsTube($tube)
	{
		return $this->_dispatch(new Command\StatsTubeCommand($tube));
	}

	/**
	 * Gives statistical information about the beanstalkd system as a whole.
	 *
	 * @return object
	 */
	public function stats()
	{
		return $this->_dispatch(new Command\StatsCommand());
	}

	/**
	 * Allows a worker to request more time to work on a job.
	 *
	 * This is useful for jobs that potentially take a long time, but you still want
	 * the benefits of a TTR pulling a job away from an unresponsive worker.  A worker
	 * may periodically tell the server that it's still alive and processing a job
	 * (e.g. it may do this on DEADLINE_SOON).
	 *
	 * @param Job $job
	 * @chainable
	 */
	public function touch($job)
	{
		$this->_dispatch(new Command\TouchCommand($job));
		return $this;
	}

	/**
	 * Change to the specified tube name for publishing jobs to.
	 * This method would be called 'use' if it were not a PHP reserved word.
	 *
	 * @param string $tube
	 * @chainable
	 */
	public function useTube($tube)
	{
		$this->_dispatch(new Command\UseCommand($tube));
		return $this;
	}

	/**
	 * Add the specified tube to the watchlist, to reserve jobs from.
	 *
	 * @param string $tube
	 * @chainable
	 */
	public function watch($tube)
	{
		$this->_dispatch(new Command\WatchCommand($tube));
		return $this;
	}

	// ----------------------------------------

	/**
	 * @param Pheanstalk_Command $command
	 * @return Response
	 */
	private function _dispatch($command)
	{
		return $this->_connection->dispatchCommand($command);
	}
}
