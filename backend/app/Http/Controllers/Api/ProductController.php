<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    /**
     * Retorna a lista de produtos ativos com Eager Loading e filtro de categorias.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->where('is_active', true)
            ->with([
                'categories',
                'images',
                'primaryImage',
                'colors',
                'sizes',
            ]);

        // Filtragem relacional Many-to-Many por slug ou UUID de categoria
        if ($request->filled('category') && $request->category !== 'all') {
            $categoryFilter = (string) $request->input('category');
            $query->whereHas('categories', function ($q) use ($categoryFilter): void {
                $q->where('slug', $categoryFilter)
                    ->orWhere('categories.id', $categoryFilter);
            });
        }

        $products = $query->latest()->get();

        return ProductResource::collection($products);
    }

    /**
     * Retorna um produto individual pelo slug ou ID com todos os campos textuais e relacionamentos.
     */
    public function show(Product $product): ProductResource|JsonResponse
    {
        if (! $product->is_active) {
            return response()->json(['message' => 'Produto indisponível.'], 404);
        }

        $product->loadMissing([
            'images',
            'primaryImage',
            'categories',
            'colors',
            'sizes',
        ]);

        return new ProductResource($product);
    }
}
