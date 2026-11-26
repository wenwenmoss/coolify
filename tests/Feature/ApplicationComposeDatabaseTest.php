<?php

use App\Actions\Database\StartDatabaseImport;
use App\Actions\Docker\GetContainersStatus;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\PushServerUpdateJob;
use App\Livewire\Project\Database\CreateScheduledBackup;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['broadcasting.default' => 'null']);
});

function composeBackupApplication(string $version = '5'): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => <<<'YAML'
services:
  web:
    image: nginx:alpine
  db:
    image: postgres:17
  mysql:
    image: mysql:8
  mongo:
    image: mongo:7
  cache:
    image: redis:7
YAML,
    ]);
    $application->forceFill(['compose_parsing_version' => $version])->save();

    return $application;
}

it('registers compose databases without changing their identities or schedules on reparse', function (string $version) {
    $application = composeBackupApplication($version);
    $application->parse();
    $database = $application->databases()->where('name', 'db')->sole();
    $backup = $database->scheduledBackups()->create([
        'team_id' => $application->team()->id,
        'frequency' => '0 0 * * *',
    ]);
    $otherApplication = composeBackupApplication($version);
    $otherApplication->parse();
    $otherDatabase = $otherApplication->databases()->where('name', 'db')->sole();
    $application->docker_compose_raw = str_replace('postgres:17', 'postgres:18', $application->docker_compose_raw);
    $application->save();

    $application->parse();

    expect($application->databases()->pluck('name')->sort()->values()->all())->toBe(['cache', 'db', 'mongo', 'mysql'])
        ->and($otherDatabase->fresh()->image)->toBe('postgres:17')
        ->and($database->fresh()->image)->toBe('postgres:18')
        ->and($database->fresh()->service_id)->toBeNull()
        ->and($database->fresh()->scheduledBackups()->sole()->id)->toBe($backup->id)
        ->and($database->team()->id)->toBe($application->team()->id)
        ->and($backup->server()->id)->toBe($application->destination->server->id)
        ->and($application->databases()->where('name', 'cache')->sole()->isBackupSolutionAvailable())->toBeFalse();
})->with(['legacy v1' => '1', 'legacy v2' => '2', 'v3' => '3', 'v4' => '4', 'current' => '5']);

it('does not create or update production database records when parsing a preview', function (string $version) {
    $application = composeBackupApplication($version);
    $application->parse(pull_request_id: 7);
    expect($application->databases()->count())->toBe(0);

    $application->parse();
    $application->docker_compose_raw = str_replace('postgres:17', 'postgres:18', $application->docker_compose_raw);
    $application->save();
    $application->parse(pull_request_id: 7);

    expect($application->databases()->where('name', 'db')->sole()->image)->toBe('postgres:17');
})->with(['legacy' => '2', 'current' => '5']);

it('keeps schedules when a compose database is removed and later returns', function () {
    $application = composeBackupApplication();
    $application->parse();
    $database = $application->databases()->where('name', 'db')->sole();
    $backup = $database->scheduledBackups()->create(['team_id' => $application->team()->id, 'frequency' => '0 0 * * *']);
    $original = $application->docker_compose_raw;
    $application->update(['docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n"]);
    $application->parse();

    expect($database->fresh()->is_removed_from_compose)->toBeTrue()
        ->and($database->fresh()->isBackupSolutionAvailable())->toBeFalse()
        ->and($backup->fresh())->not->toBeNull();

    $application->update(['docker_compose_raw' => $original]);
    $application->parse();
    expect($database->fresh()->is_removed_from_compose)->toBeFalse()
        ->and($database->fresh()->isBackupSolutionAvailable())->toBeTrue();
});

it('tracks the database status independently of other compose services', function () {
    $application = composeBackupApplication();
    $application->parse();

    $application->syncComposeDatabaseStatuses(collect(['web' => 'exited', 'db' => 'running:healthy']));

    expect($application->databases()->where('name', 'db')->sole()->status)->toBe('running:healthy')
        ->and($application->databases()->where('name', 'mysql')->sole()->status)->toBe('exited');
});

it('delegates backup permissions to the owning application across session teams', function () {
    $application = composeBackupApplication();
    $application->parse();
    $database = $application->databases()->where('name', 'db')->sole();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $application->team()->members()->attach($owner, ['role' => 'owner']);
    $application->team()->members()->attach($member, ['role' => 'member']);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($member, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    expect(Gate::forUser($owner)->allows('manageBackups', $database))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('uploadBackup', $database))->toBeTrue()
        ->and(Gate::forUser($member)->allows('manageBackups', $database))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $database))->toBeFalse();
});

it('creates a database backup schedule from the application backup page', function () {
    $application = composeBackupApplication();
    $application->parse();
    $database = $application->databases()->where('name', 'db')->sole();
    $user = User::factory()->create();
    $application->team()->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $application->team()]);

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', '0 0 * * *')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('project.application.backup.index', [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ]));

    expect($database->scheduledBackups()->sole()->team_id)->toBe($application->team()->id);
});

it('removes database schedules when the owning application is permanently deleted', function () {
    $application = composeBackupApplication();
    $application->parse();
    $database = $application->databases()->where('name', 'db')->sole();
    $backup = $database->scheduledBackups()->create(['team_id' => $application->team()->id, 'frequency' => '0 0 * * *']);

    $application->forceDelete();

    expect(ServiceDatabase::withTrashed()->find($database->id))->toBeNull()
        ->and(ScheduledDatabaseBackup::find($backup->id))->toBeNull();
});

function fakeComposeDatabaseContainers(Application $application, bool $includeProduction = true): void
{
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    $server = $application->destination->server;
    $server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $application->team()->id])->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $application->unsetRelation('destination');
    $container = fn (string $name, string $service, int $pr, string $created, bool $oneoff = false): string => json_encode([
        'ID' => $name,
        'Names' => $name,
        'State' => 'running',
        'Status' => 'Up 1 minute',
        'CreatedAt' => $created,
        'Labels' => "com.docker.compose.service={$service},coolify.applicationUuid={$application->uuid},coolify.pullRequestId={$pr},com.docker.compose.oneoff=".($oneoff ? 'True' : 'False'),
    ]);
    $containers = [
        $container('preview-db', 'db', 7, '2026-11-25 12:00:00 +0000'),
        $container('web-production', 'web', 0, '2026-11-25 12:00:00 +0000'),
        $container('oneoff-db', 'db', 0, '2026-11-25 12:00:00 +0000', true),
    ];
    if ($includeProduction) {
        $containers[] = $container('db-app-20261125T100000', 'db', 0, '2026-11-25 10:00:00 +0000');
        $containers[] = $container('db-app-20261125T110000', 'db', 0, '2026-11-25 11:00:00 +0000');
    }
    $output = implode(PHP_EOL, $containers);
    Process::fake(function ($process) use ($output) {
        $command = remoteProcessScript($process);
        if (str_contains($command, 'docker ps')) {
            return Process::result(output: $output);
        }
        if (str_contains($command, "grep -E '^(POSTGRES")) {
            return Process::result(output: "POSTGRES_USER=postgres\nPOSTGRES_DB=app\nPOSTGRES_PASSWORD=secret");
        }
        if (str_contains($command, 'du -b')) {
            return Process::result(output: '128');
        }
        if (str_contains($command, 'stat -c %s')) {
            return Process::result(output: '128');
        }
        if (str_contains($command, 'od -An -tx1')) {
            return Process::result(output: '50 47 44 4d 50 01');
        }

        return Process::result();
    });
}

it('backs up the current production container and keeps a stable directory across deployments', function () {
    $application = composeBackupApplication();
    $application->parse();
    fakeComposeDatabaseContainers($application);
    Notification::fake();
    $application->team()->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_success_email_notifications' => true]);
    $database = $application->databases()->where('name', 'db')->sole();
    $database->update(['status' => 'running:healthy']);
    $backup = $database->scheduledBackups()->create([
        'team_id' => $application->team()->id,
        'frequency' => '0 0 * * *',
        'save_s3' => false,
    ]);

    (new DatabaseBackupJob($backup))->handle();

    $execution = $backup->executions()->sole();
    expect($execution->status)->toBe('success');
    expect($execution->filename)->toContain('db-'.$application->uuid.'/');
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "'db-app-20261125T110000' pg_dump"));
    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), "'preview-db' pg_dump"));
    Notification::assertSentTo($application->team(), App\Notifications\Database\BackupSuccess::class);
});

it('refuses to use a preview or one-off container when production is stopped', function () {
    $application = composeBackupApplication();
    $application->parse();
    fakeComposeDatabaseContainers($application, includeProduction: false);

    expect(fn () => $application->databases()->where('name', 'db')->sole()->containerName())
        ->toThrow(RuntimeException::class, 'The Compose database has no running production container.');
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'docker ps'));
});

it('restores through the existing service database import pipeline into the current production container', function () {
    $application = composeBackupApplication();
    $application->parse();
    fakeComposeDatabaseContainers($application);
    $user = User::factory()->create();
    $application->team()->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $application->team()]);
    Queue::fake([App\Jobs\CoolifyTask::class]);
    $database = $application->databases()->where('name', 'db')->sole();
    $database->update(['status' => 'running']);

    $activity = app(StartDatabaseImport::class)->handle(
        $database,
        new DatabaseImportSource('server', path: '/srv/backups/app.dmp'),
        $application->team()->id,
    );

    expect(RemoteProcessCommand::read($activity))->toContain("'db-app-20261125T110000'");
    Queue::assertPushed(App\Jobs\CoolifyTask::class);
});

it('keeps application database records and schedules during orphan cleanup', function () {
    Queue::fake();
    $application = composeBackupApplication();
    $application->parse();
    $database = $application->databases()->where('name', 'db')->sole();
    $backup = $database->scheduledBackups()->create(['team_id' => $application->team()->id, 'frequency' => '0 0 * * *']);

    $this->artisan('cleanup:stucked-resources')->assertSuccessful();

    expect($database->fresh())->not->toBeNull();
    expect($backup->fresh())->not->toBeNull();
    Queue::assertNotPushed(App\Jobs\DeleteResourceJob::class);
});

it('shows database schedules on the application backup page', function () {
    $this->withoutVite();
    $application = composeBackupApplication();
    $application->parse();
    $user = User::factory()->create();
    $application->team()->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $application->team()]);
    $parameters = [
        'project_uuid' => $application->environment->project->uuid,
        'environment_uuid' => $application->environment->uuid,
        'application_uuid' => $application->uuid,
    ];

    $application->team()->update(['show_boarding' => false]);
    Illuminate\Support\Facades\Cache::flush();

    $this->get(route('project.application.backup.index', $parameters))
        ->assertOk()
        ->assertSee('db database backups')
        ->assertSeeLivewire('project.database.scheduled-backups');
});

it('rejects schedule creation by a read-only member even if they own the session team', function () {
    $application = composeBackupApplication();
    $application->parse();
    $member = User::factory()->create();
    $application->team()->members()->attach($member, ['role' => 'member']);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($member, ['role' => 'owner']);
    $this->actingAs($member);
    session(['currentTeam' => $otherTeam]);
    $database = $application->databases()->where('name', 'db')->sole();

    Livewire::test(CreateScheduledBackup::class, ['database' => $database])
        ->set('frequency', '0 0 * * *')
        ->call('submit');

    expect($database->scheduledBackups()->count())->toBe(0);
});

it('updates database statuses from production snapshots without using a preview status', function (string $reader) {
    $application = composeBackupApplication();
    $application->parse();
    $server = $application->destination->server;
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $labels = [
        'coolify.managed' => 'true',
        'coolify.applicationUuid' => $application->uuid,
        'coolify.pullRequestId' => '0',
        'com.docker.compose.service' => 'db',
    ];
    $previewLabels = [...$labels, 'coolify.pullRequestId' => '7'];

    if ($reader === 'push') {
        (new PushServerUpdateJob($server, ['containers' => [
            ['name' => 'db-production', 'state' => 'running', 'health_status' => 'healthy', 'labels' => $labels],
            ['name' => 'db-preview', 'state' => 'exited', 'labels' => $previewLabels],
        ]]))->handle();
    } else {
        GetContainersStatus::make()->handle($server, collect([
            ['Name' => '/db-production', 'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']], 'Config' => ['Labels' => $labels]],
            ['Name' => '/db-preview', 'State' => ['Status' => 'exited'], 'Config' => ['Labels' => $previewLabels]],
        ]));
    }

    expect($application->databases()->where('name', 'db')->sole()->status)->toBe('running:healthy');
    expect($application->databases()->where('name', 'mysql')->sole()->status)->toBe('exited');
})->with(['push', 'poll']);

it('uses the same contextual image detection as service stacks', function () {
    $application = composeBackupApplication();
    $application->update(['docker_compose_raw' => <<<'YAML'
services:
  api:
    image: postgres:17
    healthcheck:
      test: [CMD, curl, 'http://localhost/health']
  mysql:
    image: mysql:8
YAML]);

    $application->parse();

    expect($application->databases()->pluck('name')->all())->toBe(['mysql']);
});

it('preserves service-owned database targets when parsing an application', function () {
    $application = composeBackupApplication();
    $service = Service::factory()->create([
        'environment_id' => $application->environment_id,
        'server_id' => $application->destination->server_id,
    ]);
    $serviceDatabase = $service->databases()->create(['name' => 'db', 'image' => 'postgres:16']);

    $application->parse();

    expect($serviceDatabase->fresh()->image)->toBe('postgres:16');
    expect($serviceDatabase->backupContainerName())->toBe('db-'.$service->uuid);
    expect($serviceDatabase->containerName())->toBe('db-'.$service->uuid);
    expect($serviceDatabase->server()->id)->toBe($service->server_id);
});

it('does not import into a database removed from the production compose file', function () {
    $application = composeBackupApplication();
    $application->parse();
    $database = $application->databases()->where('name', 'db')->sole();
    $database->forceFill(['is_removed_from_compose' => true, 'status' => 'running'])->save();
    Process::fake();

    expect(fn () => app(StartDatabaseImport::class)->handle(
        $database,
        new DatabaseImportSource('server', path: '/srv/backups/app.dmp'),
        $application->team()->id,
    ))->toThrow(DatabaseImportException::class, 'The database is no longer present in the Compose file.');
    Process::assertNothingRan();
});
