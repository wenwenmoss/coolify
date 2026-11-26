<?php

/**
 * Explicit env files keep their order and options. The project .env is never added implicitly.
 */
it('keeps only explicitly declared env files', function (mixed $envFile, array $expected) {
    expect(composeEnvFileEntries($envFile))->toBe($expected);
})->with([
    'no env_file' => [null, []],
    'one file as a string' => ['.env.defaults', ['.env.defaults']],
    'a list of files' => [['.env.defaults', './config/app.env'], ['.env.defaults', './config/app.env']],
    'explicit project env before other files' => [['.env', '.env.local'], ['.env', '.env.local']],
    'explicit project env with a dot path' => [['./.env', '.env.local'], ['./.env', '.env.local']],
    'long syntax' => [[['path' => '.env.defaults', 'required' => false, 'format' => 'raw']], [['path' => '.env.defaults', 'required' => false, 'format' => 'raw']]],
]);

it('resolves an env file from the directory of the Compose file', function (string $path, string $composeDirectory, ?string $expected) {
    expect(composeEnvFileProjectPath($path, $composeDirectory))->toBe($expected);
})->with([
    'file next to a root Compose file' => ['.env.defaults', '', '.env.defaults'],
    'dot path' => ['./config/app.env', '', 'config/app.env'],
    'file next to a Compose file in a subdirectory' => ['.env.defaults', 'deploy', 'deploy/.env.defaults'],
    'parent directory inside the repository' => ['../shared.env', 'deploy/prod', 'deploy/shared.env'],
    'outside the repository' => ['../secrets.env', '', null],
    'absolute path' => ['/etc/environment', '', null],
    'empty path' => ['', '', null],
]);
