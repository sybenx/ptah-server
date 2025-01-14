<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\DeploymentData;
use App\Models\Service;
use App\Models\Swarm;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $services = Service::with(['latestDeployment' => function ($query) {
            $query->with(['latestTaskGroup' => function ($query) {
                $query->with('latestTask');
            }]);
        }])->orderBy('name')->get();

        $swarmExists = Swarm::exists();

        return Inertia::render('Services/Index', ['services' => $services, 'swarmExists' => $swarmExists]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $swarm = auth()->user()->currentTeam->swarms()->first();

        $networks = $swarm->networks;
        $nodes = $swarm->nodes;
        $dockerRegistries = $swarm->data->registries;
        $s3Storages = $swarm->data->s3Storages;

        $deploymentData = DeploymentData::make([
            'networkName' => $networks->first()->name,
        ]);

        return Inertia::render('Services/Create', [
            'networks' => $networks,
            'nodes' => $nodes,
            'deploymentData' => $deploymentData,
            'dockerRegistries' => $dockerRegistries,
            's3Storages' => $s3Storages,
            'marketplaceUrl' => config('ptah.marketplace_url'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreServiceRequest $request)
    {
        $deploymentData = DeploymentData::validateAndCreate($request->get('deploymentData'));

        $team = auth()->user()->currentTeam;
        $swarm = $team->swarms()->firstOrFail();

        $service = Service::make($request->validated());
        $service->team_id = $team->id;
        $service->swarm_id = $swarm->id;

        DB::transaction(function () use ($service, $deploymentData) {
            $service->save();

            $service->deploy($deploymentData);
        });

        return to_route('services.deployments', $service)
            ->with('success', 'Service created and deployment scheduled successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Service $service)
    {
        $service->load(['latestDeployment', 'team', 'swarm']);

        $networks = $service->swarm->networks;
        $nodes = $service->swarm->nodes;
        $dockerRegistries = $service->swarm->data->registries;
        $s3Storages = $service->swarm->data->s3Storages;

        return Inertia::render('Services/Show', [
            'service' => $service,
            'networks' => $networks,
            'nodes' => $nodes,
            'dockerRegistries' => $dockerRegistries,
            's3Storages' => $s3Storages,
        ]);
    }

    public function deployments(Service $service)
    {
        $service->load(['deployments' => function ($deployments) {
            $deployments->with(['latestTaskGroup' => function ($taskGroups) {
                $taskGroups->with([
                    'invoker',
                    'tasks' => function ($tasks) {}]);
            }]);
        }]);

        return Inertia::render('Services/Deployments', ['service' => $service]);
    }

    public function deploy(Service $service, DeploymentData $deploymentData)
    {
        DB::transaction(function () use ($service, $deploymentData) {
            $service->deploy($deploymentData);
        });

        return to_route('services.deployments', $service);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Service $service)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateServiceRequest $request, Service $service)
    {
        $service->update($request->validated());

        session()->flash('success', 'Service updated successfully!');

        return to_route('services.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Service $service)
    {
        DB::transaction(function () use ($service) {
            $service->delete();
        });

        session()->flash('success', "Service '{$service->name}' deleted successfully!");

        return to_route('services.index');
    }
}
