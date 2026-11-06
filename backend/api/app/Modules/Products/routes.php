<?php

declare(strict_types=1);

use App\Modules\Products\Http\Controllers\AdjustStockController;
use App\Modules\Products\Http\Controllers\CreateProductController;
use App\Modules\Products\Http\Controllers\ListCategoriesController;
use App\Modules\Products\Http\Controllers\ListProductsController;
use App\Modules\Products\Http\Controllers\ShowProductController;
use App\Modules\Products\Http\Controllers\UpdateProductController;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Facades\Route;

/*
| Products module routes, registered by ProductsServiceProvider under /api/v1
| with the "api" middleware group.
*/

// Public catalogue reads: the same bytes for every caller, so ETag/304 apply.
Route::middleware('etag')->group(function (): void {
    Route::get('/categories', ListCategoriesController::class);
    Route::get('/products', ListProductsController::class);
    Route::get('/products/{productId}', ShowProductController::class);
});
Route::post('/products', CreateProductController::class)->middleware(['auth', 'can:create,'.Product::class]);
Route::patch('/products/{productId}', UpdateProductController::class)->middleware(['auth', 'can:update,'.Product::class]);
Route::put('/products/{productId}/stock', AdjustStockController::class)->middleware(['auth', 'can:adjustStock,'.Product::class]);
