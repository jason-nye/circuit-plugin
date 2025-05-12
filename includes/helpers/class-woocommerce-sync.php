<?php

class WooCommerceSync {

    public function createCategory(array $data) {
        $name = $data['name'] ?? null;
        $description = $data['description'] ?? '';

        if (!$name) {
            return null; // Name is required
        }

        $term = wp_insert_term(
            $name,
            'product_cat',
            [
                'description' => $description,
                'slug' => sanitize_title($name),
            ]
        );

        return !is_wp_error($term) ? $term['term_id'] : null;
    }

    public function updateCategory($categoryId, array $data) {
        $name = $data['name'] ?? null;
        $description = $data['description'] ?? '';

        if (!$name) {
            return false; // Name is required
        }

        $result = wp_update_term(
            $categoryId,
            'product_cat',
            [
                'name' => $name,
                'description' => $description,
                'slug' => sanitize_title($name),
            ]
        );

        return !is_wp_error($result);
    }

    public function createProduct(array $data) {
        // Create a variable product
        $product = new WC_Product_Variable();
        $name = $data['name'] ?? null;
        $description = $data['description'] ?? '';
        $sku = $data['sku'] ?? '';
        $category_ids = $data['category_ids'] ?? [];

        if (!$name) {
            return null; // Name is required
        }

        $product->set_name($name);
        $product->set_description($description);
        $product->set_sku($sku);

        if (!empty($category_ids)) {
            $product->set_category_ids($category_ids);
        }

        $productId = $product->save();

        // Create variations if needed
        if (isset($data['variations']) && is_array($data['variations'])) {
            foreach ($data['variations'] as $variationData) {
                $this->saveVariation($productId, $variationData);
            }
        }

        return $productId;
    }

    public function updateProduct($productId, array $data) {
        $product = wc_get_product($productId);

        if (!$product) {
            return false;
        }

        // Check if it's a variable product
        if ($product instanceof WC_Product_Variable && isset($data['variations'])) {
            foreach ($data['variations'] as $variationData) {
                $this->saveVariation($productId, $variationData);
            }
        }

        if (isset($data['name'])) {
            $product->set_name($data['name']);
        }
        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }
        if (isset($data['sku'])) {
            $product->set_sku($data['sku']);
        }
        if (isset($data['category_ids'])) {
            $product->set_category_ids($data['category_ids']);
        }

        $product->save();

        return true;
    }

    private function saveVariation($parentId, array $variationData) {
        $variationId = null;

        // Identify existing variation by SKU or other unique property
        if (isset($variationData['sku'])) {
            $existingVariation = $this->getVariationBySku($parentId, $variationData['sku']);
            if ($existingVariation) {
                $variationId = $existingVariation->get_id();
            }
        }

        // If a variation exists, update it; otherwise, create a new one
        $variation = $variationId ? new WC_Product_Variation($variationId) : new WC_Product_Variation();

        $variation->set_parent_id($parentId);

        if (isset($variationData['attributes'])) {
            $variation->set_attributes($variationData['attributes']);
        }
        if (isset($variationData['price'])) {
            $variation->set_price($variationData['price']);
            $variation->set_regular_price($variationData['price']);
        }
        if (isset($variationData['sku'])) {
            $variation->set_sku($variationData['sku']);
        }
        if (isset($variationData['stock_quantity'])) {
            $variation->set_stock_quantity($variationData['stock_quantity']);
            $variation->set_manage_stock(true);
        }

        $variation->save();
    }

    private function getVariationBySku($parentId, $sku) {
        $product = wc_get_product($parentId);
        $variations = $product->get_children();

        foreach ($variations as $variationId) {
            $variation = wc_get_product($variationId);
            if ($variation->get_sku() === $sku) {
                return $variation;
            }
        }

        return null;
    }
}

?>