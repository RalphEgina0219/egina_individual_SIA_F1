<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->when(!$request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->withCount('orders')
            ->orderBy('name')
            ->get();

        return $this->ok($branches);
    }

    public function store(Request $request): JsonResponse
    {
        $branch = Branch::create($this->validated($request));

        return $this->ok($branch, 'Branch created successfully', 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $branch->update($this->validated($request, $branch));

        return $this->ok($branch, 'Branch updated successfully');
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('branches', 'code')->ignore($branch?->id)],
            'name' => 'required|string|max:100',
            'address' => 'nullable|string|max:200',
            'city' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'required|boolean',
        ]);
    }
}
