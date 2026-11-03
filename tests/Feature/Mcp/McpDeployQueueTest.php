<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'app.maintenance.driver' => 'file',
    ]);
    Queue::fake();

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function mcpDeployQueueCall(string $tool, array $arguments)
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-deploy-queue', ['read', 'deploy'])->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ]);
}

function mcpDeployQueueBody($response): array
{
    $response->assertOk();

    return json_decode($response->json('result.content.0.text'), true)['data'] ?? [];
}

function runningDeploymentOf(Application $application, Server $server): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'deployment_uuid' => 'running-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => $server->id,
        'server_name' => $server->name,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);
}

test('deploy tool starts a deployment at once when the application has none running', function () {
    $body = mcpDeployQueueBody(mcpDeployQueueCall('deploy', ['uuid' => $this->application->uuid]));

    expect($body['ok'])->toBeTrue()
        ->and(ApplicationDeploymentQueue::query()->where('deployment_uuid', $body['deployment_uuid'])->value('status'))
        ->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
});

test('deploy tool queues a forced deployment behind the one already running', function () {
    $running = runningDeploymentOf($this->application, $this->server);

    $body = mcpDeployQueueBody(mcpDeployQueueCall('deploy', ['uuid' => $this->application->uuid, 'force' => true]));

    expect($body['ok'])->toBeTrue()
        ->and(ApplicationDeploymentQueue::query()->where('deployment_uuid', $body['deployment_uuid'])->value('status'))
        ->toBe('queued')
        ->and($running->fresh()->status)->toBe('in_progress');
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('deploy tool skips a deployment of the commit that is already running', function () {
    runningDeploymentOf($this->application, $this->server);

    $body = mcpDeployQueueBody(mcpDeployQueueCall('deploy', ['uuid' => $this->application->uuid]));

    expect($body['ok'])->toBeFalse()
        ->and($body['message'])->toBe('Deployment already queued for this commit.')
        ->and(ApplicationDeploymentQueue::query()->count())->toBe(1);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('control start does not start a second deployment while one is running', function () {
    runningDeploymentOf($this->application, $this->server);

    $response = mcpDeployQueueCall('control', [
        'resource' => 'application',
        'action' => 'start',
        'uuid' => $this->application->uuid,
    ]);
    $response->assertOk();

    expect(ApplicationDeploymentQueue::query()->where('status', 'in_progress')->count())->toBe(1);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});
