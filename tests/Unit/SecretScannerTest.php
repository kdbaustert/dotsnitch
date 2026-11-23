<?php

declare(strict_types=1);

use App\Support\SecretScanner;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dotsnitch-test-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    $this->dir = realpath($this->dir);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

// Built from parts, so nothing that reads this repo mistakes them for real ones.
function fakeGitHubToken(): string
{
    return 'ghp_'.str_repeat('a1B2', 9);
}

it('names the line and kind of each secret', function (string $line, string $kind) {
    file_put_contents("{$this->dir}/config", "# settings\n{$line}\n");

    expect((new SecretScanner)->scan("{$this->dir}/config"))->toBe([['path' => "{$this->dir}/config", 'line' => 2, 'kind' => $kind]]);
})->with([
    'private key' => ['-----BEGIN OPENSSH PRIVATE KEY-----', 'a private key'],
    'GitHub token in an export' => [fn () => 'export GITHUB_TOKEN='.fakeGitHubToken(), 'a GitHub token'],
    'gh hosts.yml' => [fn () => '    oauth_token: gho_'.str_repeat('x', 36), 'a GitHub token'],
    'fine-grained GitHub token' => ['token = github_pat_'.str_repeat('Z', 30), 'a GitHub token'],
    'npm token' => ['//registry.npmjs.org/:_authToken=npm_abc', 'an npm token'],
    'AWS key' => ['aws_access_key_id = AKIA'.str_repeat('Q', 16), 'an AWS access key'],
    'Slack token' => ['SLACK=xoxb-'.str_repeat('1', 12), 'a Slack token'],
    'password' => ['password = hunter2hunter2', 'a password or token'],
    'quoted JSON key' => ['  "apiKey": "abcdefgh12345678",', 'a password or token'],
    'client secret' => ['CLIENT_SECRET="s3cr3tvalue"', 'a password or token'],
]);

it('leaves values alone that are read from somewhere else', function (string $line) {
    file_put_contents("{$this->dir}/config", "{$line}\n");

    expect((new SecretScanner)->scan("{$this->dir}/config"))->toBe([]);
})->with([
    '//registry.npmjs.org/:_authToken=${NPM_TOKEN}',
    'export GITHUB_TOKEN=$(gh auth token)',
    'export PASSWORD_STORE_DIR=~/.password-store',
    'token_file = /Users/me/.token',
    'password = <your password>',
    'tokenizer = whitespace-split',
    'api_key = short',
    "'password' => ['required', Password::min(12)->mixedCase()],",
    'authToken: process.env.NPM_TOKEN,',
    "const token = useCookie('token')",
    'if (token == "abcdefghijkl") {',
]);

it('reads a folder all the way down, leaving out .git and binary files', function () {
    mkdir("{$this->dir}/gh/nested", 0755, true);
    mkdir("{$this->dir}/gh/.git", 0755, true);
    file_put_contents("{$this->dir}/gh/hosts.yml", "github.com:\n    user: me\n    oauth_token: ".fakeGitHubToken()."\n");
    file_put_contents("{$this->dir}/gh/nested/config.yml", "password: hunter2hunter2\n");
    file_put_contents("{$this->dir}/gh/.git/config", 'password = hunter2hunter2');
    file_put_contents("{$this->dir}/gh/cache.bin", "\0password = hunter2hunter2");

    expect((new SecretScanner)->scan("{$this->dir}/gh"))->toBe([
        ['path' => "{$this->dir}/gh/hosts.yml", 'line' => 3, 'kind' => 'a GitHub token'],
        ['path' => "{$this->dir}/gh/nested/config.yml", 'line' => 1, 'kind' => 'a password or token'],
    ]);
});

it("finds nothing when the path isn't there", function () {
    expect((new SecretScanner)->scan("{$this->dir}/missing"))->toBe([]);
});
