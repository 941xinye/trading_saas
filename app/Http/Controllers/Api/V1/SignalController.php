<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SignalResource;
use App\Models\Signal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 信号 API Controller（只读：index / show）。
 *
 * POC 阶段：可加 query 过滤 subscription_id / symbol / direction / source。
 */
class SignalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = Signal::query()->latest('triggered_at')->paginate();

        return SignalResource::collection($records);
    }

    public function show(Signal $signal): SignalResource
    {
        return new SignalResource($signal);
    }
}
