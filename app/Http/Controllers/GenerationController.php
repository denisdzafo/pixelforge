<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use App\Services\Generations\GenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly GenerationService $generationService) {}

    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $from = $request->query('from');

        return Inertia::render('generations/Create', $this->generationService->formData($user, is_string($from) ? $from : null));
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{prompt: string, size: string, quality: string, reference_images?: list<string>|null} $input */
        $input = $request->validated();
        $this->generationService->submit($user, $input);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your image is on its way. You can follow it here.']);

        return to_route('generations.index');
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($this->generationService->history($user)),
        ]);
    }

    public function retry(Request $request, string $generation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->generationService->retry($user, $generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Retrying with the same settings.']);

        return to_route('generations.index');
    }
}
