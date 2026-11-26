<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Collection;

class EnvFileIsolationDeploymentJob extends ApplicationDeploymentJob
{
    public array $commands = [];

    public array $warnings = [];

    public string $repositoryFiles = '';

    public function __construct() {}

    public function execute_remote_command(...$commands)
    {
        foreach ($commands as $command) {
            $this->commands[] = $command;
            if (isset($command['save'])) {
                $outputs = (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this);
                $outputs->put($command['save'], $this->repositoryFiles);
            }
        }
    }
}

function envFileIsolationDeploymentJob(int $parserVersion): EnvFileIsolationDeploymentJob
{
    $job = new EnvFileIsolationDeploymentJob;
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message) use ($job) {
        $job->warnings[] = $message;
    });
    foreach ([
        'application' => Application::factory()->make(['compose_parsing_version' => (string) $parserVersion]),
        'application_deployment_queue' => $queue,
        'docker_compose_location' => '/deploy/compose.yaml',
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/app',
        'saved_outputs' => collect(),
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return $job;
}

function deploymentEnvFiles(EnvFileIsolationDeploymentJob $job, array $services): Collection
{
    return (new ReflectionMethod(ApplicationDeploymentJob::class, 'withRepositoryEnvFiles'))
        ->invoke($job, collect(['services' => $services]));
}

it('never adds the project env file to a service without env_file', function (int $parserVersion) {
    $job = envFileIsolationDeploymentJob($parserVersion);

    $compose = deploymentEnvFiles($job, ['api' => ['image' => 'nginx'], 'db' => ['image' => 'postgres']]);

    expect($compose->all())->toBe(['services' => ['api' => ['image' => 'nginx'], 'db' => ['image' => 'postgres']]]);
    expect($job->commands)->toBe([]);
})->with([1, 2, 3, 4]);

it('keeps explicit env files in order with their options and resolves repository paths', function () {
    $job = envFileIsolationDeploymentJob(4);
    $job->repositoryFiles = "deploy/api.env\ndeploy/shared.env";

    $compose = deploymentEnvFiles($job, [
        'api' => ['env_file' => ['.env', ['path' => './api.env', 'required' => false, 'format' => 'raw'], './.env', 'shared.env']],
        'db' => ['image' => 'postgres'],
    ]);

    expect($compose['services']['api']['env_file'])->toBe([
        '.env',
        ['path' => 'deploy/api.env', 'required' => false, 'format' => 'raw'],
        './.env',
        'deploy/shared.env',
    ]);
    expect($compose['services']['db'])->not->toHaveKey('env_file');
    expect($job->warnings)->toBe([]);
});

it('keeps explicit legacy env files without replacing them with the project env file', function (int $parserVersion) {
    $job = envFileIsolationDeploymentJob($parserVersion);
    $services = ['api' => ['env_file' => ['api.env', '.env', 'shared.env']]];

    $compose = deploymentEnvFiles($job, $services);

    expect($compose['services'])->toBe($services);
    expect($job->commands)->toBe([]);
})->with([1, 2]);

it('does not inject project secrets as a fallback for a missing repository env file', function () {
    $job = envFileIsolationDeploymentJob(4);

    $compose = deploymentEnvFiles($job, ['api' => ['image' => 'nginx', 'env_file' => ['missing.env']]]);

    expect($compose['services']['api'])->toBe(['image' => 'nginx']);
    expect($job->warnings)->toBe(['Warning: the env_file missing.env of service api is not a file in the repository. Coolify skips it.']);
});
