<?php

namespace App\Traits;

use App\Models\Product;
use App\Models\Store;
use App\Models\Topping;
use Illuminate\Support\Str;

trait ProductToppingSync
{
    protected function syncProductToppings(
        Product $product,
        ?Store $store,
        mixed $toppingsInput
    ): void {

        if (!$store) {
            $product->toppings()->sync([]);
            return;
        }

        $rows = $this->normalizeToppingRows(
            $toppingsInput
        );

        $existingIds = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn(array $row)
                            => (int)($row['id'] ?? 0),
                        $rows
                    ),
                    static fn(int $id)
                        => $id > 0
                )
            )
        );

        $existingById =
            empty($existingIds)
            ? collect()
            : Topping::query()
                ->where(
                    'store_id',
                    $store->id
                )
                ->whereIn(
                    'id',
                    $existingIds
                )
                ->get()
                ->keyBy(
                    fn(Topping $t)
                        => (int)$t->id
                );

        $toppingIds = [];

        foreach($rows as $row)
        {
            $attributes = [

                'store_id'
                    => $store->id,

                'name'
                    => $row['name'],

                'price'
                    => $row['price'],

                'sale_price'
                    => $row['sale_price'],

                'stock'
                    => $row['stock'],

                'sku'
                    => $row['sku'],

                'enabled'
                    => $row['enabled'],
            ];

            $topping =
                $row['id']
                ? $existingById->get(
                    (int)$row['id']
                )
                : null;

            if($topping)
            {
                $topping->fill(
                    $attributes
                );

                if(
                    $topping->isDirty()
                ){
                    $topping->save();
                }
            }
            else
            {
                $topping =
                    Topping::create(
                        $attributes
                    );
            }

            $toppingIds[
                (int)$topping->id
            ] = (int)$topping->id;
        }

        $product
            ->toppings()
            ->sync(
                array_values(
                    $toppingIds
                )
            );
    }

    protected function normalizeToppingRows(
        mixed $toppingsInput
    ): array {

        if(
            !is_array(
                $toppingsInput
            )
        ){
            return [];
        }

        $rows=[];

        foreach(
            $toppingsInput
            as $item
        ){

            if(
                !is_array(
                    $item
                )
            ){
                continue;
            }

            $name = trim(
                (string)data_get(
                    $item,
                    'name',
                    ''
                )
            );

            if(
                $name === ''
            ){
                continue;
            }

            $id =
                data_get(
                    $item,
                    'id'
                );

            $rows[]=[

                'id'=>
                    is_numeric($id)
                    ? (int)$id
                    : null,

                'name'=>
                    $name,

                'price'=>
                    $this->normalizeNullableNumber(
                        data_get(
                            $item,
                            'price',
                            0
                        )
                    ) ?? 0,

                'sale_price'=>
                    $this->normalizeNullableNumber(
                        data_get(
                            $item,
                            'sale_price'
                        )
                    ),

                'stock'=>
                    $this->normalizeNullableInteger(
                        data_get(
                            $item,
                            'stock'
                        )
                    ),

                'sku'=>
                    $this->normalizeNullableString(
                        data_get(
                            $item,
                            'sku'
                        )
                    ),

                'enabled'=>
                    (bool)data_get(
                        $item,
                        'enabled',
                        true
                    )

            ];
        }

        return $rows;
    }

    protected function normalizeNullableString(
        mixed $value
    ): ?string {

        if(
            !is_scalar(
                $value
            )
        ){
            return null;
        }

        $value = trim(
            (string)$value
        );

        return $value === ''
            ? null
            : $value;
    }

    protected function normalizeNullableNumber(
        mixed $value
    ): ?float {

        if(
            $value === null
            || $value === ''
        ){
            return null;
        }

        if(
            !is_numeric(
                $value
            )
        ){
            return null;
        }

        return (float)$value;
    }

    protected function normalizeNullableInteger(
        mixed $value
    ): ?int {

        if(
            $value === null
            || $value === ''
        ){
            return null;
        }

        if(
            !is_numeric(
                $value
            )
        ){
            return null;
        }

        return (int)$value;
    }
}