<?php

namespace App\Models;

use App\Models\DeploymentData\Caddy;
use App\Models\DeploymentData\EnvVar;
use App\Models\DeploymentData\Process;
use App\Models\NodeTasks\ApplyCaddyConfig\ApplyCaddyConfigMeta;
use App\Models\NodeTasks\DeleteService\DeleteServiceMeta;
use App\Traits\HasOwningTeam;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

class Deployment extends Model
{
    use HasFactory;
    use HasOwningTeam;

    protected $fillable = [
        'service_id',
        'team_id',
        'task_group_id',
        'data',
    ];

    protected $casts = [
        'data' => DeploymentData::class,
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function taskGroups(): HasManyThrough
    {
        return $this->hasManyThrough(NodeTaskGroup::class, NodeTask::class, 'meta__deployment_id', 'id', 'id', 'task_group_id')->orderByDesc('id');
    }

    public function latestTaskGroup(): HasOneThrough
    {
        return $this->hasOneThrough(NodeTaskGroup::class, NodeTask::class, 'meta__deployment_id', 'id', 'id', 'task_group_id')->latest();
    }

    public function previousDeployment(): ?Deployment
    {
        return $this->service->deployments()->where('id', '<', $this->id)->latest('id')->get()->first();
    }

    public function makeResourceName($name): string
    {
        return $this->service->makeResourceName('dpl_'.$this->id.'_'.$name);
    }

    public function scopeLatestDeployments(EloquentBuilder $query): EloquentBuilder
    {
        return $query->whereIn('id', function (QueryBuilder $query) {
            $query
                ->selectRaw('max("latest_deployments_query"."id")')
                ->from('deployments', 'latest_deployments_query')
                ->join('services', 'services.id', '=', 'latest_deployments_query.service_id')
                ->whereNull('services.deleted_at')
                ->groupBy('latest_deployments_query.service_id');
        });
    }

    public function resourceLabels(): array
    {
        return dockerize_labels([
            'service.id' => $this->service_id,
            'service.slug' => $this->service->slug,
            'deployment.id' => $this->id,
        ]);
    }

    public function asNodeTasks(): array
    {
        /* @var DeploymentData $data */
        $data = $this->data;

        $tasks = [];

        foreach ($data->processes as $process) {
            $tasks = array_merge($tasks, $process->asNodeTasks($this));
        }

        $previousProcesses = $this->previousDeployment()?->data->processes ?? [];

        foreach ($previousProcesses as $process) {
            if ($this->findProcess($process->dockerName) === null) {
                $tasks[] = [
                    'type' => NodeTaskType::DeleteService,
                    'meta' => new DeleteServiceMeta($this->service_id, $process->dockerName, $this->service->name),
                    'payload' => [
                        'ServiceName' => $process->dockerName,
                    ],
                ];
            }
        }

        $this->saveQuietly();

        $caddyHandlers = [];

        $this->latestDeployments()->each(function ($deployment) use (&$caddyHandlers) {
            foreach ($deployment->data->processes as $process) {
                $caddyHandlers[] = collect($process->caddy)->map(function (Caddy $caddy) use ($deployment, $process) {
                    $routes = [];

                    $routes[] = [
                        'match' => [
                            [
                                'host' => [$caddy->domain],
                                'path' => [$caddy->path],
                            ],
                        ],
                        'handle' => [
                            [
                                'handler' => 'reverse_proxy',
                                'headers' => [
                                    'request' => [
                                        'set' => $this->getForwardedHeaders($caddy),
                                    ],
                                    'response' => [
                                        'set' => [
                                            'X-Powered-By' => ['https://ptah.sh'],
                                            'X-Ptah-Rule-Id' => [$caddy->id],
                                        ],
                                    ],
                                ],
                                'transport' => $this->getTransportOptions($caddy, $process),
                                'upstreams' => [
                                    [
                                        'dial' => "{$process->name}.{$deployment->data->internalDomain}:{$caddy->targetPort}",
                                    ],
                                ],
                            ],
                        ],
                    ];

                    foreach ($process->redirectRules as $redirectRule) {
                        $regexpName = dockerize_name($redirectRule->id);

                        $pathTo = preg_replace("/\\$(\d+)/", "{http.regexp.$regexpName.$1}", $redirectRule->pathTo);

                        $routes[] = [
                            'match' => [
                                [
                                    'host' => [$redirectRule->domainFrom],
                                    'path_regexp' => [
                                        'name' => $regexpName,
                                        'pattern' => $redirectRule->pathFrom,
                                    ],
                                ],
                            ],
                            'handle' => [
                                [
                                    'handler' => 'static_response',
                                    'status_code' => (string) $redirectRule->statusCode,
                                    'headers' => [
                                        'X-Powered-By' => ['https://ptah.sh'],
                                        'X-Ptah-Rule-Id' => [$redirectRule->id],
                                        'Location' => ["{http.request.scheme}://{$redirectRule->domainTo}{$pathTo}"],
                                    ],
                                ],
                            ],
                        ];
                    }

                    return [
                        'apps' => [
                            'http' => [
                                'servers' => [
                                    "listen_{$caddy->publishedPort}" => [
                                        'listen' => [
                                            "0.0.0.0:{$caddy->publishedPort}",
                                        ],
                                        'routes' => $routes,
                                    ],
                                ],
                            ],
                        ],
                    ];
                })->toArray();
            }
        });

        // Flatten array
        $caddyHandlers = array_merge(...$caddyHandlers);

        $caddyTask = [];

        if (! empty($caddyHandlers)) {
            $caddy = [
                'apps' => [
                    'http' => [
                        'servers' => (object) [],
                    ],
                ],
            ];

            foreach ($caddyHandlers as $handler) {
                $caddy = array_merge_recursive($caddy, $handler);
            }

            foreach ($caddy['apps']['http']['servers'] as $name => $value) {
                $caddy['apps']['http']['servers'][$name]['listen'] = array_unique($value['listen']);
                $caddy['apps']['http']['servers'][$name]['routes'] = $this->sortRoutes($value['routes']);

                $caddy['apps']['http']['servers'][$name]['routes'][] = [
                    'match' => [
                        [
                            'host' => ['*'],
                            'path' => ['/*'],
                        ],
                    ],
                    'handle' => [
                        [
                            'handler' => 'static_response',
                            'status_code' => '404',
                            'headers' => [
                                'X-Powered-By' => ['https://ptah.sh'],
                                'Content-Type' => ['text/html; charset=utf-8'],
                            ],
                            'body' => file_get_contents(resource_path('support/caddy/404.html')),
                        ],
                    ],
                ];
            }

            $caddyTask[] = [
                'type' => NodeTaskType::ApplyCaddyConfig,
                'meta' => ApplyCaddyConfigMeta::from([
                    'deploymentId' => $this->id,
                ]),
                'payload' => [
                    'caddy' => $caddy,
                ],

            ];
        }

        return [
            ...$tasks,
            ...$caddyTask,
        ];
    }

    protected function getTransportOptions(Caddy $caddy, Process $process): array
    {
        if ($caddy->targetProtocol === 'http') {
            return [
                'protocol' => 'http',
            ];
        }

        if ($caddy->targetProtocol === 'fastcgi') {
            return [
                'protocol' => 'fastcgi',
                'root' => $process->fastCgi->root,
                'env' => (object) collect($process->fastCgi->env)->reduce(fn ($carry, EnvVar $var) => [...$carry, $var->name => $var->value], []),
            ];
        }

        throw new InvalidArgumentException("Unsupported Caddy target protocol: {$caddy->targetProtocol}");
    }

    protected function getForwardedHeaders(Caddy $caddy): array
    {
        if ($caddy->publishedPort === 80) {
            return [
                'X-Forwarded-Proto' => ['http'],
                'X-Forwarded-Schema' => ['http'],
                'X-Forwarded-Host' => [$caddy->domain],
                'X-Forwarded-Port' => ['80'],
            ];
        }

        return [
            'X-Forwarded-Proto' => ['https'],
            'X-Forwarded-Schema' => ['https'],
            'X-Forwarded-Host' => [$caddy->domain],
            'X-Forwarded-Port' => ['443'],
        ];
    }

    protected function sortRoutes($routes): array
    {
        return collect($routes)
            ->sort(function (array $a, array $b) {
                $weightsA = $this->getRouteWeights($a);
                $weightsB = $this->getRouteWeights($b);

                $segmentsResult = $weightsA['segments'] <=> $weightsB['segments'];
                if ($segmentsResult === 0) {
                    return $weightsA['wildcards'] <=> $weightsB['wildcards'];
                }

                return $segmentsResult;
            })
            ->values()
            ->toArray();
    }

    protected function getRouteWeights($route): array
    {
        // Let's prioritize regexp routes to be first to execute
        if (isset($route['match'][0]['path_regexp'])) {
            return [
                'segments' => -100,
                'wildcards' => 100,
            ];
        }

        $path = $route['match'][0]['path'][0];

        $pathSegments = count(explode('/', $path)) * -1;
        $wildcardSegments = count(explode('*', $path));

        return [
            'segments' => $pathSegments,
            'wildcards' => $wildcardSegments,
        ];
    }

    public function findProcess(string $dockerName): ?Process
    {
        return $this->data->findProcess($dockerName);
    }
}
