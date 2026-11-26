<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('isolates service environments while keeping explicit sharing and interpolation', function (string $resourceClass, int $parserVersion) {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $attributes = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  api:
    image: nginx:alpine
    build:
      context: .
      args:
        BUILD_TOKEN: ${BUILD_TOKEN}
        MODE: build-mode
    environment:
      DATABASE_URL: postgres://${DB_USER}:${DB_PASSWORD}@db/app
      MODE: runtime-mode
      EMPTY: ''
      UNSET: null
    env_file:
      - path: api.env
        required: false
        format: raw
  db:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=${DB_USER}
      - POSTGRES_PASSWORD=${DB_PASSWORD}
  worker:
    image: busybox:latest
    environment:
      DATABASE_URL: postgres://${DB_USER}:${DB_PASSWORD}@db/app
  shared:
    image: busybox:latest
    env_file:
      - .env
      - shared.env
  sidecar:
    image: busybox:latest
YAML,
    ];
    $attributes[$resourceClass === Service::class ? 'server_id' : 'build_pack'] = $resourceClass === Service::class ? $server->id : 'dockercompose';
    $resource = $resourceClass::factory()->create($attributes);
    $resource->forceFill(['compose_parsing_version' => (string) $parserVersion])->save();
    foreach (['DB_USER' => 'app', 'DB_PASSWORD' => 'db-secret', 'BUILD_TOKEN' => 'build-secret', 'UNUSED_SECRET' => 'unused-secret'] as $key => $value) {
        $resource->environment_variables()->create(['key' => $key, 'value' => $value, 'is_preview' => false]);
    }

    $resource->parse();
    $compose = convertToArray($resource->fresh()->parse());

    expect($compose['services']['api']['env_file'])->toBe([['path' => 'api.env', 'required' => false, 'format' => 'raw']]);
    expect($compose['services']['shared']['env_file'])->toBe(['.env', 'shared.env']);
    expect($compose['services']['api']['build']['args'])->toBe(['BUILD_TOKEN' => '${BUILD_TOKEN}', 'MODE' => 'build-mode']);
    expect($compose['services']['api']['environment'])->toMatchArray([
        'DATABASE_URL' => 'postgres://${DB_USER}:${DB_PASSWORD}@db/app',
        'MODE' => 'runtime-mode',
        'EMPTY' => '',
        'UNSET' => null,
        'COOLIFY_RESOURCE_UUID' => $resource->uuid,
    ])->not->toHaveKeys(['DB_USER', 'DB_PASSWORD', 'BUILD_TOKEN', 'UNUSED_SECRET', 'POSTGRES_PASSWORD']);
    expect($compose['services']['worker']['environment']['DATABASE_URL'])->toBe('postgres://${DB_USER}:${DB_PASSWORD}@db/app');
    expect($compose['services']['db']['environment'])->toMatchArray([
        'POSTGRES_USER' => '${DB_USER}',
        'POSTGRES_PASSWORD' => '${DB_PASSWORD}',
    ])->not->toHaveKeys(['DB_USER', 'DB_PASSWORD', 'BUILD_TOKEN', 'UNUSED_SECRET']);
    foreach (['db', 'worker', 'sidecar'] as $name) {
        expect($compose['services'][$name])->not->toHaveKey('env_file');
        expect($compose['services'][$name]['environment'])->toHaveKey('COOLIFY_CONTAINER_NAME');
    }
    expect($compose['services']['sidecar']['environment'])->not->toHaveKeys(['DATABASE_URL', 'POSTGRES_PASSWORD', 'UNUSED_SECRET']);
    expect($resource->environment_variables()->pluck('value', 'key')->only(['DB_PASSWORD', 'BUILD_TOKEN', 'UNUSED_SECRET'])->all())
        ->toBe(['DB_PASSWORD' => 'db-secret', 'BUILD_TOKEN' => 'build-secret', 'UNUSED_SECRET' => 'unused-secret']);
})->with([
    'application parser v4' => [Application::class, 4],
    'service parser v4' => [Service::class, 4],
]);

it('keeps a single service deployment scoped to its declared variables', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:alpine
    environment:
      - APP_KEY=${APP_KEY}
      - PASSTHROUGH
YAML,
    ]);
    foreach (['APP_KEY' => 'app-secret', 'PASSTHROUGH' => 'saved-value', 'UNUSED_SECRET' => 'unused-secret'] as $key => $value) {
        $application->environment_variables()->create(['key' => $key, 'value' => $value, 'is_preview' => false]);
    }

    $compose = convertToArray($application->fresh()->parse());

    expect($compose['services']['app'])->not->toHaveKey('env_file');
    expect($compose['services']['app']['environment'])->toMatchArray([
        'APP_KEY' => '${APP_KEY}',
        'PASSTHROUGH' => 'saved-value',
    ])->not->toHaveKey('UNUSED_SECRET');
});

it('isolates legacy application parsers and keeps metadata without a shared env_file', function (int $parserVersion) {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:alpine
    build:
      context: .
      args:
        BUILD_TOKEN: ${BUILD_TOKEN}
    environment:
      APP_KEY: ${APP_KEY}
  sidecar:
    image: busybox:latest
YAML,
    ]);
    $application->forceFill(['compose_parsing_version' => (string) $parserVersion])->save();
    $application->environment_variables()->create(['key' => 'APP_KEY', 'value' => 'app-secret', 'is_preview' => false]);
    $application->environment_variables()->create(['key' => 'BUILD_TOKEN', 'value' => 'build-secret', 'is_preview' => false]);

    $compose = convertToArray($application->fresh()->parse());

    expect($compose['services']['app']['environment'])->toMatchArray([
        'APP_KEY' => '${APP_KEY}',
        'COOLIFY_RESOURCE_UUID' => '${COOLIFY_RESOURCE_UUID}',
        'COOLIFY_BRANCH' => '${COOLIFY_BRANCH}',
    ])->not->toHaveKey('BUILD_TOKEN');
    expect($compose['services']['sidecar']['environment'])->not->toHaveKeys(['APP_KEY', 'BUILD_TOKEN']);
    expect($compose['services']['app'])->not->toHaveKey('env_file');
    expect($compose['services']['sidecar'])->not->toHaveKey('env_file');
})->with([1, 2]);
