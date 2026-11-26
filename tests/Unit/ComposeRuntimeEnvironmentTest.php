<?php

it('keeps only declared variables and routing metadata in the container environment', function () {
    $environment = collect([
        'DATABASE_URL' => 'postgres://${DB_USER}:${DB_PASSWORD}@db/app',
        'EMPTY' => '',
        'UNSET' => null,
        'DB_USER' => 'private-user',
        'DB_PASSWORD' => 'private-password',
        'BUILD_TOKEN' => 'build-secret',
        'COOLIFY_BUILD_TOKEN' => 'build-secret',
        'SERVICE_PASSWORD_DB' => 'generated-secret',
        'COOLIFY_RESOURCE_UUID' => 'resource-uuid',
        'SERVICE_URL_API' => 'https://api.example.com',
        'SERVICE_FQDN_API' => 'api.example.com',
        'SERVICE_NAME_DB' => 'db',
    ]);

    $result = composeRuntimeEnvironment($environment, ['DATABASE_URL' => '', 'EMPTY' => '', 'UNSET' => null]);

    expect($result->all())->toBe([
        'DATABASE_URL' => 'postgres://${DB_USER}:${DB_PASSWORD}@db/app',
        'EMPTY' => '',
        'UNSET' => null,
        'COOLIFY_RESOURCE_UUID' => 'resource-uuid',
        'SERVICE_URL_API' => 'https://api.example.com',
        'SERVICE_FQDN_API' => 'api.example.com',
        'SERVICE_NAME_DB' => 'db',
    ]);
});

it('recognizes list declarations without exposing undeclared interpolation inputs', function () {
    $environment = collect(['PASSWORD' => '${SERVICE_PASSWORD_DB}', 'BARE' => null, 'VALUE' => 'a=b', 'BUILD_ONLY' => 'secret']);

    $result = composeRuntimeEnvironment($environment, ['PASSWORD=${SERVICE_PASSWORD_DB}', 'BARE', 'VALUE=a=b']);

    expect($result->all())->toBe(['PASSWORD' => '${SERVICE_PASSWORD_DB}', 'BARE' => null, 'VALUE' => 'a=b']);
});

it('keeps a declared FQDN resolved through the project env file', function () {
    $result = composeRuntimeEnvironment(collect(['APP_URL' => '${SERVICE_URL_API}']), [
        'SERVICE_FQDN_API' => '/api',
        'APP_URL' => '${SERVICE_URL_API}',
    ]);

    expect($result->all())->toBe([
        'APP_URL' => '${SERVICE_URL_API}',
        'SERVICE_FQDN_API' => '${SERVICE_FQDN_API}',
    ]);
});
