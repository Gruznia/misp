<?php

declare(strict_types=1);

App::uses('Worker', 'Tools/BackgroundJobs');
App::uses('BackgroundJob', 'Tools/BackgroundJobs');

/**
 * BackgroundJobs Tool
 * 
 * Utility class to queue jobs, run them and monitor workers.
 * 
 * The background jobs rely in two main scripts running:
 *      * StartWorkerShell.php
 *      * MonitorWorkersShell.php
 * 
 * To run them manually (debug only):
 *      $ ./Console/cake start_worker [queue]
 *      $ ./Console/cake monitor_workers
 * 
 * It is recommended to run these commands with [Supervisor](http://supervisord.org).
 * `Supervisor` has an extensive feature set to manage scripts as services, 
 * such as autorestart, parallel execution, logging, monitoring and much more. 
 * All can be managed via the terminal or a XML-RPC API.
 * 
 * Use the following configuration as a template for the services:
 *      /etc/supervisor/conf.d/misp-workers-monitor.conf:
 *      [program:misp-workers-monitor]
 *      command=/var/www/MISP/app/Console/cake monitor_workers
 *      numprocs=1
 *      autostart=true
 *      autorestart=true
 *      redirect_stderr=false
 *      stderr_logfile=/var/www/MISP/app/tmp/logs/misp-workers-monitor-errors.log
 *      stdout_logfile=/var/www/MISP/app/tmp/logs/misp-workers-monitor.log
 *      user=www-data
 * 
 *      /etc/supervisor/conf.d/misp-workers.conf:
 *      [group:misp-workers]
 *      programs=default,email,cache,prio,update
 *
 *      ; one section per each queue type is required
 *      [program:default]
 *      command=/var/www/MISP/app/Console/cake start_worker default
 *      process_name=%(program_name)s_%(process_num)02d
 *      numprocs=5 ; adjust the amount of parallel workers to your MISP usage 
 *      autostart=true
 *      autorestart=true
 *      redirect_stderr=false
 *      stderr_logfile=/var/www/MISP/app/tmp/logs/misp-workers-errors.log
 *      stdout_logfile=/var/www/MISP/app/tmp/logs/misp-workers.log
 *      user=www-data
 * 
 */
class BackgroundJobsTool
{
    /** @var Redis */
    private $RedisConnection;

    /** @var \Supervisor\Supervisor */
    private $Supervisor;

    public const MISP_WORKERS_PROCESS_GROUP = 'misp-workers';

    public const
        DEFAULT_QUEUE = 'default',
        EMAIL_QUEUE = 'email',
        CACHE_QUEUE = 'cache',
        PRIO_QUEUE = 'prio',
        UPDATE_QUEUE = 'update';

    public const VALID_QUEUES = [
        self::DEFAULT_QUEUE,
        self::EMAIL_QUEUE,
        self::CACHE_QUEUE,
        self::PRIO_QUEUE,
        self::UPDATE_QUEUE,
    ];

    public const
        CMD_EVENT = 'event',
        CMD_SERVER = 'server',
        CMD_ADMIN = 'admin';

    public const ALLOWED_COMMANDS = [
        self::CMD_EVENT,
        self::CMD_SERVER,
        self::CMD_ADMIN
    ];

    public const CMD_TO_SHELL_DICT = [
        self::CMD_EVENT => 'EventShell',
        self::CMD_SERVER => 'ServerShell',
        self::CMD_ADMIN => 'AdminShell'
    ];

    public const JOB_STATUS_PREFIX = 'job_status';
    public const WORKER_STATUS_PREFIX = 'worker_status';

    /** @var array */
    private $settings;

    /**
     * Initialize
     * 
     * Settings should have the following format:
     *      [
     *           'enabled' => true,
     *           'redis_host' => 'localhost',
     *           'redis_port' => 6379,
     *           'redis_password' => '',
     *           'redis_database' => 1,
     *           'redis_namespace' => 'background_jobs',
     *           'max_job_history_ttl' => 86400
     *           'track_status' => 86400,
     *           'supervisor_host' => 'localhost',
     *           'supervisor_port' => '9001',
     *           'supervisor_user' => '',
     *           'supervisor_password' => '',
     *      ]
     *
     * @param array $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;

        if (!$this->RedisConnection && $this->settings['enabled'] === true) {
            $this->RedisConnection = $this->createRedisConnection();
        }

        if (!$this->Supervisor && $this->settings['enabled'] === true) {
            $this->Supervisor = $this->createSupervisorConnection();
        }
    }

    /**
     * Enqueue a Job.
     *
     * @param string $queue Queue name, e.g. 'default'.
     * @param string $command Command of the job.
     * @param array $args Arguments passed to the job.
     * @param boolean|null $trackStatus Whether to track the status of the job.
     * @param int|null $jobId Id of the relational database record representing the job.
     * @return string Background Job Id.
     * @param array $metadata Related to the job.
     * @throws InvalidArgumentExceptiony
     */
    public function enqueue(
        string $queue,
        string $command,
        array $args = [],
        $trackStatus = null,
        int $jobId = null,
        array $metadata = []
    ): string {

        if (!$this->settings['enabled']) {
            return $this->resqueEnqueue($queue, self::CMD_TO_SHELL_DICT[$command], $args, $trackStatus, $jobId);
        }

        $this->validateQueue($queue);
        $this->validateCommand($command);

        $backgroundJob = new BackgroundJob(
            [
                'id' => CakeText::uuid(),
                'command' => $command,
                'args' => $args,
                'trackStatus' => $trackStatus ?? $this->settings['track_status'],
                'metadata' => $metadata
            ]
        );

        $this->RedisConnection->rpush(
            $queue,
            $backgroundJob
        );

        $this->update($backgroundJob);

        if ($jobId) {
            $this->updateJobProcessId($jobId, $backgroundJob->id());
        }

        return $backgroundJob->id();
    }

    /**
     * Enqueue a Job using the CakeResque.
     * @deprecated
     * 
     * @param string $queue Name of the queue to enqueue the job to.
     * @param string $class Class of the job.
     * @param array $args Arguments passed to the job.
     * @param boolean $trackStatus Whether to track the status of the job.
     * @param int|null $jobId Id of the relational database record representing the job.
     * @return string Job Id.
     */
    private function resqueEnqueue(
        string $queue,
        string $class,
        $args = [],
        $trackStatus = null,
        int $jobId = null
    ): string {

        $process_id = CakeResque::enqueue(
            $queue,
            $class,
            $args,
            $trackStatus
        );

        if ($jobId) {
            $this->updateJobProcessId($jobId, $process_id);
        }

        return $process_id;
    }

    /**
     *  Dequeue a BackgroundJob.
     *  If the queue is empty the read is blocked until a job is pushed to this queue or the timeout is reached.
     *
     * @param string $queue Queue name, e.g. 'default'.
     * @param int    $timeout Time to block the read if the queue is empty.
     *                  Must be less than your configured `read_write_timeout` 
     *                  for the redis connection.
     * 
     * @return BackgroundJob|null.
     * @throws Exception
     */
    public function dequeue($queue, int $timeout = 30): ?BackgroundJob
    {
        $this->validateQueue($queue);

        $rawJob = $this->RedisConnection->blpop($queue, $timeout);

        if (!empty($rawJob)) {
            try {
                return new BackgroundJob($rawJob[1]);
            } catch (Exception $exception) {
                CakeLog::error("Failed to parse job, invalid format: {$rawJob[1]}. exception: {$exception->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Get the job status.
     *
     * @param string $jobId Backgroun Job Id.
     * 
     * @return BackgroundJob|null job status.
     * 
     */
    public function getJob(string $jobId): ?BackgroundJob
    {
        $rawJob = $this->RedisConnection->get(
            self::JOB_STATUS_PREFIX . ':' . $jobId
        );

        if ($rawJob) {
            return new BackgroundJob($rawJob);
        }

        return null;
    }

    /**
     * Get the queues's names.
     *
     * @return array Array containing the queues' names.
     */
    public function getQueues(): array
    {
        return self::VALID_QUEUES;
    }

    /**
     * Clear all the queue's jobs.
     *
     * @param string $queue Queue name, e.g. 'default'.
     * 
     * @return boolean True on success, false on failure.
     */
    public function clearQueue($queue): bool
    {
        $this->validateQueue($queue);

        return (bool) $this->RedisConnection->del($queue);
    }

    /**
     * Get worker by PID.
     *
     * @return Worker|null Worker instance.
     */
    public function getWorker(int $workerPid): ?Worker
    {
        $rawWorker = $this->RedisConnection->get(self::WORKER_STATUS_PREFIX . ':' . $workerPid);

        if ($rawWorker) {
            return new Worker($rawWorker);
        }

        return null;
    }

    /**
     * Get all workers' instances.
     *
     * @return Worker[] List of worker's instances.
     */
    public function getWorkers(): array
    {
        $pattern = self::WORKER_STATUS_PREFIX . ':*';

        // get existing workers status keys
        $iterator = null;
        $workersKeys = [];
        while ($keys = $this->RedisConnection->scan($iterator, $pattern)) {
            foreach ($keys as $key) {
                $workersKeys[] = $key;
            }
        }

        if (!$workersKeys) {
            return [];
        }

        // get workers status
        $workersStatus = $this->RedisConnection->mget($workersKeys);

        $workers = [];
        foreach ($workersStatus as $worker) {
            $workers[] = new Worker($worker);
        }

        return $workers;
    }

    /**
     * Get the number of jobs inside a queue.
     *
     * @param  string $queue Queue name, e.g. 'default'.
     * 
     * @return integer Number of jobs.
     */
    public function getQueueSize(string $queue): int
    {
        $this->validateQueue($queue);

        if (!$this->settings['enabled']) {
            return CakeResque::getQueueSize($queue);
        }

        return $this->RedisConnection->llen($queue);
    }

    /**
     * Update job
     *
     * @param BackgroundJob $job
     * 
     * @return void
     */
    public function update(BackgroundJob $job): void
    {
        $job->setUpdatedAt(time());

        $this->RedisConnection->setex(
            self::JOB_STATUS_PREFIX . ':' . $job->id(),
            $this->settings['max_job_history_ttl'],
            $job
        );
    }

    /**
     * Run job
     *
     * @param BackgroundJob $job
     * 
     * @return integer Process return code.
     */
    public function run(BackgroundJob $job): int
    {
        $job->setStatus(BackgroundJob::STATUS_RUNNING);
        CakeLog::info("[JOB ID: {$job->id()}] - started.");

        $this->update($job);

        $job = $job->run();

        $this->update($job);

        return $job->returnCode();
    }

    /**
     * Register worker
     *
     * @param integer $workerPid
     * @param string  $queue
     * @param integer $createdAt
     * 
     * @return void
     */
    public function registerWorker(Worker $worker): void
    {
        $this->RedisConnection->set(
            self::WORKER_STATUS_PREFIX . ':' . $worker->pid(),
            $worker
        );
    }

    /**
     * Update worker
     *
     * @param integer $workerPid
     * @param integer $status
     * 
     * @return void
     */
    public function updateWorkerStatus(int $workerPid, int $status): void
    {
        $worker = $this->getWorker($workerPid);

        if (!$worker) {
            CakeLog::warning("updateWorkerStatus: worker with PID: {$workerPid} not found.");
            return;
        }

        $worker->setUpdatedAt(time());
        $worker->setStatus($status);

        $this->RedisConnection->set(
            self::WORKER_STATUS_PREFIX . ':' . $workerPid,
            $worker
        );
    }

    /**
     * Unregister worker
     *
     * @param integer $workerPid
     * 
     * @return void
     */
    public function unregisterWorker(int $workerPid): void
    {
        $this->RedisConnection->del(self::WORKER_STATUS_PREFIX . ':' . $workerPid);
    }

    /**
     * Start worker by name
     *
     * @param string $name
     * @param boolean $waitForRestart
     * @return boolean
     */
    public function startWorker(string $name, bool $waitForRestart = false): bool
    {
        $this->validateWorkerName($name);

        return $this->Supervisor->startProcess(
            sprintf(
                '%s:%s',
                self::MISP_WORKERS_PROCESS_GROUP,
                $name
            ),
            $waitForRestart
        );
    }

    /**
     * Stop worker by name or pid
     *
     * @param string|int $id
     * @param boolean $waitForRestart
     * @return boolean
     */
    public function stopWorker($id, bool $waitForRestart = false): bool
    {
        if (is_numeric($id)) {
            $process = $this->getProcessByPid((int)$id);
            $name = $process->offsetGet('name');
        } else {
            $name = $id;
        }

        $this->validateWorkerName($name);

        return $this->Supervisor->stopProcess(
            sprintf(
                '%s:%s',
                self::MISP_WORKERS_PROCESS_GROUP,
                $name
            ),
            $waitForRestart
        );
    }

    /**
     * Restarts workers
     *
     * @param boolean $waitForRestart
     * @return void
     */
    public function restartWorkers(bool $waitForRestart = false): void
    {
        $this->Supervisor->stopProcessGroup(self::MISP_WORKERS_PROCESS_GROUP, $waitForRestart);
        $this->Supervisor->startProcessGroup(self::MISP_WORKERS_PROCESS_GROUP, $waitForRestart);
    }

    /**
     * Restarts workers with status != RUNNING
     *
     * @param boolean $waitForRestart
     * @return void
     */
    public function restartDeadWorkers(bool $waitForRestart = false): void
    {
        $this->Supervisor->startProcessGroup(self::MISP_WORKERS_PROCESS_GROUP, $waitForRestart);
    }

    /**
     * Purge queue
     *
     * @param string $queue
     * @return void
     */
    public function purgeQueue(string $queue): void
    {
        $this->validateQueue($queue);

        $this->RedisConnection->del($queue);
    }

    /**
     * Validate queue
     *
     * @return boolean
     * @throws InvalidArgumentException
     */
    private function validateQueue(string $queue): bool
    {
        if (!in_array($queue, self::VALID_QUEUES)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid background job queue, must be one of: [%s]',
                    implode(', ', self::VALID_QUEUES)
                )
            );
        }

        return true;
    }

    /**
     * Validate command
     *
     * @return boolean
     * @throws InvalidArgumentException
     */
    private function validateCommand(string $command): bool
    {
        if (!in_array($command, self::ALLOWED_COMMANDS)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid command, must be one of: [%s]',
                    implode(', ', self::ALLOWED_COMMANDS)
                )
            );
        }

        return true;
    }

    /**
     * Validate worker name
     *
     * @return boolean
     * @throws InvalidArgumentException
     */
    private function validateWorkerName(string $name): bool
    {
        list($queue, $id) = explode('_', $name);

        $this->validateQueue($queue);

        if (!$this->validateQueue($queue) || !is_numeric($id)) {
            throw new InvalidArgumentException('Invalid worker name, must be one of format {queue_name}_{process_id}, example: default_00');
        }

        return true;
    }

    /**
     * @return Redis
     */
    private function createRedisConnection(): Redis
    {
        $redis = new Redis();
        $redis->connect($this->settings['redis_host'], $this->settings['redis_port']);
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
        $redis->setOption(Redis::OPT_PREFIX, $this->settings['redis_namespace'] . ':');
        $redisPassword = $this->settings['redis_password'];

        if (!empty($redisPassword)) {
            $redis->auth($redisPassword);
        }
        $redis->select($this->settings['redis_database']);

        return $redis;
    }

    /**
     * @return \Supervisor\Supervisor
     */
    private function createSupervisorConnection(): \Supervisor\Supervisor
    {
        $httpOptions = [];
        if (!empty($this->settings['supervisor_user']) && !empty($this->settings['supervisor_password'])) {
            $httpOptions = [
                'auth' => [
                    $this->settings['supervisor_user'],
                    $this->settings['supervisor_password'],
                ],
            ];
        }

        $client = new \fXmlRpc\Client(
            sprintf(
                'http://%s:%s/RPC2',
                $this->settings['supervisor_host'],
                $this->settings['supervisor_port']
            ),
            new \fXmlRpc\Transport\HttpAdapterTransport(
                new \Http\Message\MessageFactory\GuzzleMessageFactory(),
                new \GuzzleHttp\Client($httpOptions)
            )
        );

        return new \Supervisor\Supervisor($client);
    }

    private function updateJobProcessId(int $jobId, string $processId): void
    {
        $job = ClassRegistry::init('Job');
        $job->id = $jobId;
        $job->save(['process_id' => $processId]);
    }

    /**
     * Get Supervisor process by PID
     *
     * @param integer $pid
     * @return \Supervisor\Process
     *
     * @throws NotFoundException
     */
    private function getProcessByPid(int $pid): \Supervisor\Process
    {
        $procs = $this->Supervisor->getAllProcesses();

        foreach ($procs as $proc) {
            if (
                $proc->offsetGet('group') === self::MISP_WORKERS_PROCESS_GROUP &&
                $proc->offsetGet('pid')  === $pid
            ) {
                return $proc;
            }
        }

        throw new NotFoundException("Worker with pid=$pid not found.");
    }
}