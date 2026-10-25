<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\UserResource;
use App\Models\Category;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', fn (Request $request) => new UserResource($request->user()));
    Route::apiResource('products', ProductController::class);
    Route::get('categories', fn () => CategoryResource::collection(Category::orderBy('id')->paginate(100)));
    Route::get('suppliers', fn () => SupplierResource::collection(Supplier::orderBy('id')->paginate(100)));
});
