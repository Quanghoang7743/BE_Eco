<?php

namespace App\Http\Controllers;

use App\Models\ProductCombo;
use App\Http\Requests\StoreProductComboRequest;
use App\Http\Requests\UpdateProductComboRequest;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductComboController extends Controller
{
    public function index()
    {
        $combos = ProductCombo::with(['product', 'bonusProduct'])->paginate(10);
        return view('admin.product_combos.index', compact('combos'));
    }

    public function create()
    {
        $products = Product::all();
        return view('admin.product_combos.create', compact('products'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required',
            'bonus_product_id' => 'required|different:product_id',
            'buy_quantity' => 'required|integer|min:1',
            'bonus_quantity' => 'required|integer|min:1',
        ]);

        ProductCombo::create($request->all());
        return redirect()->route('admin.product-combos.index')->with('success', 'Combo created.');
    }

    public function edit(ProductCombo $productCombo)
    {
        $products = Product::all();
        return view('admin.product_combos.edit', compact('productCombo', 'products'));
    }

    public function update(Request $request, ProductCombo $productCombo)
    {
        $request->validate([
            'product_id' => 'required',
            'bonus_product_id' => 'required|different:product_id',
            'buy_quantity' => 'required|integer|min:1',
            'bonus_quantity' => 'required|integer|min:1',
        ]);

        $productCombo->update($request->all());
        return redirect()->route('admin.product-combos.index')->with('success', 'Combo updated.');
    }

    public function destroy(ProductCombo $productCombo)
    {
        $productCombo->delete();
        return redirect()->back()->with('success', 'Combo deleted.');
    }

}
