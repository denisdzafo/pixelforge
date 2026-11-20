<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Http\Resources\CreditTransactionResource;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function __construct(private readonly CreditService $creditService) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $data = $this->creditService->pageData($user);

        return Inertia::render('credits/Index', [
            'packs' => $data['packs'],
            'onHoldNanos' => $data['onHoldNanos'],
            'transactions' => CreditTransactionResource::collection($data['transactions']),
        ]);
    }

    public function purchase(PurchaseCreditsRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $amount = $this->creditService->purchase(
            $user,
            (string) $request->validated('pack'),
            (string) $request->validated('idempotency_key'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$amount->format()} to your balance."]);

        return back();
    }
}
