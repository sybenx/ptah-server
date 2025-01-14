<?php

namespace App\Models\DeploymentData;

use App\Models\Deployment;
use App\Models\NodeTasks\CreateConfig\CreateConfigMeta;
use App\Models\NodeTasks\CreateSecret\CreateSecretMeta;
use App\Models\NodeTasks\CreateService\CreateServiceMeta;
use App\Models\NodeTasks\DeleteService\DeleteServiceMeta;
use App\Models\NodeTasks\PullDockerImage\PullDockerImageMeta;
use App\Models\NodeTasks\UpdateService\UpdateServiceMeta;
use App\Models\NodeTaskType;
use App\Rules\RequiredIfArrayHas;
use Exception;
use Illuminate\Support\Str;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;

class Process extends Data
{
    public function __construct(
        public string $name,
        public ?string $dockerName,
        public ?string $dockerRegistryId,
        public string $dockerImage,
        public ReleaseCommand $releaseCommand,
        public ?string $command,
        #[DataCollectionOf(ProcessBackup::class)]
        /* @var ProcessBackup[] */
        public array $backups,
        #[DataCollectionOf(Worker::class)]
        /* @var Worker[] */
        public array $workers,
        #[Enum(LaunchMode::class)]
        public string $launchMode,
        #[DataCollectionOf(EnvVar::class)]
        /* @var EnvVar[] */
        public array $envVars,
        public SecretVars $secretVars,
        #[DataCollectionOf(ConfigFile::class)]
        /* @var ConfigFile[] */
        public array $configFiles,
        #[DataCollectionOf(ConfigFile::class)]
        /* @var ConfigFile[] */
        public array $secretFiles,
        #[DataCollectionOf(Volume::class)]
        /* @var Volume[] */
        public array $volumes,
        public ?BackupVolume $backupVolume,
        public int $replicas,
        #[DataCollectionOf(NodePort::class)]
        /* @var NodePort[] */
        public array $ports,
        #[DataCollectionOf(Caddy::class)]
        /* @var Caddy[] */
        public array $caddy,
        #[Rule(new RequiredIfArrayHas('caddy.*.targetProtocol', 'fastcgi'))]
        public ?FastCgi $fastCgi,
        #[DataCollectionOf(RedirectRule::class)]
        /* @var RedirectRule[] */
        public array $redirectRules
    ) {}

    public function findVolume(string $id): ?Volume
    {
        return collect($this->volumes)->first(fn (Volume $volume) => $volume->id === $id);
    }

    public function findProcessBackup(string $id): ?ProcessBackup
    {
        return collect($this->backups)->first(fn (ProcessBackup $backup) => $backup->id === $id);
    }

    public function findConfigFile(string $path): ?ConfigFile
    {
        return collect($this->configFiles)->first(fn (ConfigFile $file) => $file->path === $path);
    }

    public function findSecretFile(string $path): ?ConfigFile
    {
        return collect($this->secretFiles)->first(fn (ConfigFile $file) => $file->path === $path);
    }

    /**
     * @throws Exception
     */
    public function asNodeTasks(Deployment $deployment): array
    {
        if (empty($this->dockerName)) {
            $this->dockerName = dockerize_name($deployment->service->docker_name.'_'.$this->name);
        }

        $labels = $deployment->resourceLabels();
        $previous = $deployment->previousDeployment()?->findProcess($this->dockerName);

        $tasks = [];

        $previousWorkers = $previous?->workers ?? [];
        foreach ($previousWorkers as $worker) {
            if ($this->findWorker($worker->dockerName) === null) {
                $tasks[] = [
                    'type' => NodeTaskType::DeleteService,
                    'meta' => new DeleteServiceMeta($deployment->service_id, $worker->dockerName, $deployment->service->name),
                    'payload' => [
                        'ServiceName' => $worker->dockerName,
                    ],
                ];
            }
        }

        foreach ($this->workers as $worker) {
            if (! $worker->dockerName) {
                // TODO: add validation - allow only unique worker commands
                $worker->dockerName = $this->makeResourceName('wkr_'.$worker->name);
            }
        }

        foreach ($this->configFiles as $configFile) {
            $previousConfig = $previous?->findConfigFile($configFile->path);
            if ($previousConfig && $configFile->sameAs($previousConfig)) {
                $configFile->dockerName = $previousConfig->dockerName;

                continue;
            }

            $configFile->dockerName = $this->makeResourceName('dpl_'.$deployment->id.'_cfg_'.$configFile->path);

            $tasks[] = [
                'type' => NodeTaskType::CreateConfig,
                'meta' => CreateConfigMeta::from([
                    'deploymentId' => $deployment->id,
                    'processName' => $this->dockerName,
                    'path' => $configFile->path,
                    'hash' => $configFile->hash(),
                ]),
                'payload' => [
                    'SwarmConfigSpec' => [
                        'Name' => $configFile->dockerName,
                        'Data' => $configFile->base64(),
                        'Labels' => dockerize_labels([
                            ...$labels,
                            'kind' => 'config',
                            'content.hash' => $configFile->hash(),
                        ]),
                    ],
                ],
            ];
        }

        foreach ($this->secretFiles as $secretFile) {
            $previousSecret = $previous?->findSecretFile($secretFile->path);
            if ($previousSecret && ($secretFile->content === null || $secretFile->sameAs($previousSecret))) {
                $secretFile->dockerName = $previousSecret->dockerName;

                continue;
            }

            $secretFile->dockerName = $this->makeResourceName('dpl_'.$deployment->id.'_cfg_'.$secretFile->path);

            $tasks[] = [
                'type' => NodeTaskType::CreateSecret,
                'meta' => CreateSecretMeta::from([
                    'deploymentId' => $deployment->id,
                    'processName' => $this->dockerName,
                    'path' => $secretFile->path,
                    'hash' => $secretFile->hash(),
                ]),
                'payload' => [
                    'SwarmSecretSpec' => [
                        'Name' => $secretFile->dockerName,
                        'Data' => $secretFile->base64(),
                        'Labels' => dockerize_labels([
                            ...$labels,
                            'content.hash' => $secretFile->hash(),
                        ]),
                    ],
                ],
            ];
        }

        foreach ($this->volumes as $volume) {
            if (! $volume->dockerName) {
                $volume->dockerName = $this->makeResourceName($volume->name);
            }
        }

        $internalDomain = "{$this->name}.{$deployment->data->internalDomain}";

        $command = null;
        $args = null;

        if ($this->command) {
            // FIXME: use smarter CLI split - need to handle values with spaces, surrounded by the double quotes
            $splitCmd = explode(' ', $this->command);

            $command = [$splitCmd[0]];
            $args = array_slice($splitCmd, 1);
        }

        $dockerRegistry = $this->dockerRegistryId
            ? $deployment->service->swarm->data->findRegistry($this->dockerRegistryId)
            : null;

        if ($this->dockerRegistryId && is_null($dockerRegistry)) {
            throw new Exception("Docker registry '{$this->dockerRegistryId}' not found");
        }

        $authConfigName = $dockerRegistry
            ? $dockerRegistry->dockerName
            : '';

        $tasks[] = [
            'type' => NodeTaskType::PullDockerImage,
            'meta' => PullDockerImageMeta::from([
                'deploymentId' => $deployment->id,
                'processName' => $this->dockerName,
                'serviceId' => $deployment->service_id,
                'serviceName' => $deployment->service->name,
                'dockerImage' => $this->dockerImage,
            ]),
            'payload' => [
                'AuthConfigName' => $authConfigName,
                'Image' => $this->dockerImage,
                'PullOptions' => (object) [],
            ],
        ];

        $serviceTaskMeta = [
            'deploymentId' => $deployment->id,
            'dockerName' => $this->dockerName,
            'serviceId' => $deployment->service_id,
            'serviceName' => $deployment->service->name,
        ];

        $volumes = $this->volumes;

        $mounts = collect($volumes)
            ->map(fn (Volume $volume) => [
                'Type' => 'volume',
                'Source' => $volume->dockerName,
                'Target' => $volume->path,
                'VolumeOptions' => [
                    'Labels' => dockerize_labels([
                        'id' => $volume->id,
                        ...$labels,
                    ]),
                ],
            ])
            ->toArray();

        // TODO: if (has volumes with backups enabled OR has a Backup Script defined)
        if (count($this->volumes)) {
            if ($this->backupVolume == null) {
                $this->backupVolume = BackupVolume::validateAndCreate([
                    'id' => 'backups-'.Str::random(11),
                    'name' => 'backups',
                    'dockerName' => $this->makeResourceName('/ptah/backups'),
                    'path' => '/ptah/backups',
                ]);
            }

            $mounts[] = [
                'Type' => 'volume',
                'Source' => $this->backupVolume->dockerName,
                'Target' => $this->backupVolume->path,
                'VolumeOptions' => [
                    'Labels' => dockerize_labels([
                        'id' => $this->backupVolume->id,
                        ...$labels,
                    ]),
                ],
            ];
        }

        $envVars = $this->envVars;
        $envVars[] = EnvVar::validateAndCreate([
            'name' => 'PTAH_HOSTNAME',
            'value' => $internalDomain,
        ]);

        // FIXME: this is going to work wrong if the initial deployment is pending.
        //   Don't allow to schedule deployments if the service has not been created yet?
        //   This code is duplicated in the next block
        $actionUpdate = $deployment->service->tasks()->ofType(NodeTaskType::CreateService)->where('meta__docker_name', $this->dockerName)->completed()->exists();

        $tasks[] = [
            'type' => $actionUpdate ? NodeTaskType::UpdateService : NodeTaskType::CreateService,
            'meta' => $actionUpdate ? UpdateServiceMeta::from($serviceTaskMeta) : CreateServiceMeta::from($serviceTaskMeta),
            'payload' => [
                'AuthConfigName' => $authConfigName,
                'ReleaseCommand' => $this->getReleaseCommandPayload($deployment, $labels),
                'SecretVars' => (object) $this->getSecretVars($deployment, $previous, $labels),
                'SwarmServiceSpec' => [
                    'Name' => $this->dockerName,
                    'Labels' => $labels,
                    'TaskTemplate' => [
                        'ContainerSpec' => [
                            'Image' => $this->dockerImage,
                            'Labels' => $labels,
                            'Command' => $command,
                            'Args' => $args,
                            'Hostname' => "dpl-{$deployment->id}.{$internalDomain}",
                            'Env' => collect($envVars)->map(fn (EnvVar $var) => "{$var->name}={$var->value}")->toArray(),
                            'Mounts' => $mounts,
                            'Hosts' => [
                                $internalDomain,
                            ],
                            'Secrets' => collect($this->secretFiles)->map(fn (ConfigFile $secretFile) => [
                                'File' => [
                                    'Name' => $secretFile->path,
                                    // TODO: figure out better permissions settings (if any)
                                    'UID' => '0',
                                    'GID' => '0',
                                    'Mode' => 0777,
                                ],
                                'SecretName' => $secretFile->dockerName,
                            ])->values()->toArray(),
                            'Configs' => collect($this->configFiles)->map(fn (ConfigFile $configFile) => [
                                'File' => [
                                    'Name' => $configFile->path,
                                    // TODO: figure out better permissions settings (if any)
                                    'UID' => '0',
                                    'GID' => '0',
                                    'Mode' => 0777,
                                ],
                                'ConfigName' => $configFile->dockerName,
                            ])->values()->toArray(),
                            'Placement' => $deployment->data->placementNodeId ? [
                                'Constraints' => [
                                    "node.labels.sh.ptah.node.id=={$deployment->data->placementNodeId}",
                                ],
                            ] : [],
                        ],
                        'Networks' => [
                            [
                                'Target' => $deployment->data->networkName,
                                'Aliases' => [$internalDomain],
                            ],
                        ],
                    ],
                    'Mode' => [
                        'Replicated' => [
                            'Replicas' => $this->replicas,
                        ],
                    ],
                    'EndpointSpec' => [
                        'Ports' => collect($this->ports)->map(fn (NodePort $port) => [
                            'Protocol' => 'tcp',
                            'TargetPort' => $port->targetPort,
                            'PublishedPort' => $port->publishedPort,
                            'PublishMode' => 'ingress',
                        ])->toArray(),
                    ],
                ],
            ],
        ];

        foreach ($this->workers as $worker) {
            $actionUpdate = $deployment->service->tasks()->ofType(NodeTaskType::CreateService)->where('meta__docker_name', $worker->dockerName)->completed()->exists();

            $workerTaskMeta = [
                ...$serviceTaskMeta,
                'dockerName' => $worker->dockerName,
            ];

            $tasks[] = [
                'type' => $actionUpdate ? NodeTaskType::UpdateService : NodeTaskType::CreateService,
                'meta' => $actionUpdate ? UpdateServiceMeta::from($workerTaskMeta) : CreateServiceMeta::from($workerTaskMeta),
                'payload' => [
                    'AuthConfigName' => $authConfigName,
                    'ReleaseCommand' => (object) [],
                    'SecretVars' => (object) $this->getWorkerSecretVars($worker, $labels),
                    'SwarmServiceSpec' => [
                        'Name' => $worker->dockerName,
                        'Labels' => $labels,
                        'TaskTemplate' => [
                            'ContainerSpec' => [
                                'Image' => $this->dockerImage,
                                'Labels' => $labels,
                                'Command' => ['sh', '-c'],
                                'Args' => [
                                    $worker->command,
                                ],
                                'Hostname' => "dpl-{$deployment->id}.{$worker->name}.{$internalDomain}",
                                'Env' => collect($this->envVars)->map(fn (EnvVar $var) => "{$var->name}={$var->value}")->toArray(),
                                'Mounts' => [],
                                'HealthCheck' => [
                                    'Test' => ['NONE'],
                                ],
                                'Hosts' => [
                                    "{$worker->name}.{$internalDomain}",
                                ],
                                'Secrets' => collect($this->secretFiles)->map(fn (ConfigFile $secretFile) => [
                                    'File' => [
                                        'Name' => $secretFile->path,
                                        // TODO: figure out better permissions settings (if any)
                                        'UID' => '0',
                                        'GID' => '0',
                                        'Mode' => 0777,
                                    ],
                                    'SecretName' => $secretFile->dockerName,
                                ])->values()->toArray(),
                                'Configs' => collect($this->configFiles)->map(fn (ConfigFile $configFile) => [
                                    'File' => [
                                        'Name' => $configFile->path,
                                        // TODO: figure out better permissions settings (if any)
                                        'UID' => '0',
                                        'GID' => '0',
                                        'Mode' => 0777,
                                    ],
                                    'ConfigName' => $configFile->dockerName,
                                ])->values()->toArray(),
                                'Placement' => [],
                            ],
                            'Networks' => [
                                [
                                    'Target' => $deployment->data->networkName,
                                    'Aliases' => [
                                        "{$worker->name}.{$internalDomain}",
                                    ],
                                ],
                            ],
                        ],
                        'Mode' => [
                            'Replicated' => [
                                'Replicas' => $worker->replicas,
                            ],
                        ],
                        'UpdateConfig' => [
                            'Parallelism' => 1,
                        ],
                        'EndpointSpec' => [
                            'Ports' => [],
                        ],
                    ],
                ],
            ];
        }

        return $tasks;
    }

    protected function getSecretVars(Deployment $deployment, ?Process $previous, $labels): array|object
    {
        if (empty($this->secretVars->vars)) {
            $this->secretVars->dockerName = null;

            return (object) [];
        }

        $this->secretVars->dockerName = $this->makeResourceName('dpl_'.$deployment->id.'_secret_vars');

        $data = [
            'ConfigName' => $this->secretVars->dockerName,
            'ConfigLabels' => dockerize_labels([
                ...$labels,
                'kind' => 'secret-env-vars',
            ]),
            'Values' => (object) collect($this->secretVars->vars)
                ->reject(fn (EnvVar $var) => $var->value === null)
                ->reduce(fn ($carry, EnvVar $var) => [...$carry, $var->name => $var->value], []),
        ];

        if (! empty($previous?->secretVars->dockerName)) {
            $data['Preserve'] = collect($this->secretVars->vars)
                ->filter(fn (EnvVar $var) => $var->value === null)
                ->map(fn (EnvVar $var) => $var->name)
                ->toArray();

            $data['PreserveFromConfig'] = $previous->secretVars->dockerName;
        }

        return $data;
    }

    private function getWorkerSecretVars(Worker $worker, array $labels): array|object
    {
        if (empty($this->secretVars->vars)) {
            return (object) [];
        }

        return [
            'ConfigName' => $this->secretVars->dockerName.'_wkr_'.$worker->name,
            'ConfigLabels' => dockerize_labels([
                ...$labels,
                'kind' => 'secret-env-vars',
            ]),
            'Values' => (object) [],
            'Preserve' => collect($this->secretVars->vars)->map(fn (EnvVar $var) => $var->name)->toArray(),
            'PreserveFromConfig' => $this->secretVars->dockerName,
        ];
    }

    public function makeResourceName(string $name): string
    {
        return dockerize_name($this->dockerName.'_'.$name);
    }

    private function getReleaseCommandPayload(Deployment $deployment, array $labels): array
    {
        if (! $this->releaseCommand->command) {
            return [
                'ConfigName' => '',
                'ConfigLabels' => (object) [],
                'Command' => '',
            ];
        }

        // Always create a new config, as the command may be the same, but the image/entrypoint may be different.
        $this->releaseCommand->dockerName = $deployment->makeResourceName('release_command');

        return [
            'ConfigName' => $this->releaseCommand->dockerName,
            'ConfigLabels' => dockerize_labels([
                ...$labels,
                'kind' => 'release-command',
            ]),
            'Command' => $this->releaseCommand->command,
        ];
    }

    private function findWorker(?string $dockerName): ?Worker
    {
        if (! $dockerName) {
            return null;
        }

        return collect($this->workers)->first(fn (Worker $worker) => $worker->dockerName === $dockerName);
    }
}
