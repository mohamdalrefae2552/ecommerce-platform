<?php

declare(strict_types=1);

use App\Modules\Products\Http\Controllers\CreateProductController;
use App\Modules\Products\Http\Controllers\ListCategoriesController;
use App\Modules\Products\Http\Controllers\ListProductsController;
use App\Modules\Products\Http\Controllers\ShowProductController;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\Route;

/*
| Products module routes, registered by ProductsServiceProvider under /api/v1
| with the "api" middleware group.
*/

Route::get('/categories', ListCategoriesController::class);
Route::get('/products', ListProductsController::class);
Route::get('/products/{productId}', ShowProductController::class);
Route::post('/products', CreateProductController::class)->middleware(['auth', 'can:create,'.Product::class]);
