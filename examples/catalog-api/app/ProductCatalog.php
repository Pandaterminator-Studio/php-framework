<?php

namespace App;

use Core\Database\Connection;
use Core\Database\QueryBuilder;
use Core\Http\Request;
use Core\Http\Response;
use Core\HttpException;
use Core\Validation\Validator;

final class ProductCatalog
{
    private readonly QueryBuilder $products;

    public function __construct(Connection $database)
    {
        $this->products = $database->table('products')->select(['id', 'name', 'category', 'price_cents']);
    }

    public function index(Request $request): Response
    {
        $filters = Validator::validate($request->query(), [
            'category' => 'string|min:1|max:40',
            'page' => 'integer|min:1',
            'per_page' => 'integer|between:1,100',
        ]);
        $products = $this->products;
        if (isset($filters['category'])) {
            $products = $products->where('category', $filters['category']);
        }
        return Response::json($products->orderBy('id')->paginate($filters['page'] ?? 1, $filters['per_page'] ?? 3));
    }

    public function show(int $id): Response
    {
        $product = $this->products->where('id', $id)->first();
        if ($product === null) {
            throw new HttpException(404, 'Product niet gevonden.');
        }
        return Response::json(['data' => $product]);
    }
}
