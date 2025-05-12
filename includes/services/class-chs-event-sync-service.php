<?php

require_once CHS_PLUGIN_DIR . 'includes/helpers/class-chs-api.php';
require_once CHS_PLUGIN_DIR . 'includes/helpers/class-chs-sync.php';

if (!class_exists('CHS_EventSyncService')) {
    class CHS_EventSyncService {
        private CHS_API $api;
        private CHS_Sync $eventTypeToCategorySync;
        private CHS_Sync $clubToCategorySync;
        private CHS_Sync $eventToProduct;
        private CHS_Sync $eventPackageToVariation;
        private CHS_Sync $simpleEventPackageToProduct;
        private CHS_Sync $venueToLocationSync;

        public function __construct() {
            $this->api = new CHS_API();

            // Initialize syncs
            $this->eventTypeToCategorySync = new CHS_Sync('event_type', true, function ($source_id, $data) {
                // Check if this source_id already has a mapping
                $existing_mapped_id = $this->eventTypeToCategorySync->get($source_id);

                if ($existing_mapped_id) {
                    $existing_term = get_term($existing_mapped_id, 'event_type');
                    if ($existing_term && !is_wp_error($existing_term)) {
                        // error_log("Found existing term by mapping: " . $existing_term->name);
                        return $existing_mapped_id;
                    }
                }

                // No valid mapping found, check by name
                if (!empty($data['name'])) {
                    $existing_term = get_term_by('name', $data['name'], 'event_type');
                    if ($existing_term && !is_wp_error($existing_term)) {
                        // error_log("Found existing term by name: " . $existing_term->name);

                        // Update parent if needed
                        if (isset($data['parent_id']) && $data['parent_id'] > 0) {
                            wp_update_term($existing_term->term_id, 'event_type', [
                                'parent' => $data['parent_id']
                            ]);
                        }

                        return $existing_term->term_id;
                    }
                }
                // Create new term
                if (!empty($data['name'])) {
                    $args = [
                        'description' => '',
                        'slug' => sanitize_title($data['name']),
                        'parent' => isset($data['parent_id']) ? $data['parent_id'] : 0
                    ];

                    // error_log("Creating new term with args: " . print_r($args, true));
                    $term = wp_insert_term($data['name'], 'event_type', $args);

                    if (!is_wp_error($term)) {
                        // error_log("Created new term with ID: " . $term['term_id']);
                        return $term['term_id'];
                    }
                }

                return null;
            });

            $this->clubToCategorySync = new CHS_Sync('club', true, function ($source_id, $data) {
                if (!is_array($data)) {
                    return null;
                }

                $args = array(
                    'description' => '',
                    'slug' => sanitize_title($data['name'])
                );

                if (isset($data['parent_id']) && $data['parent_id']) {
                    $args['parent'] = $data['parent_id'];
                }

                $term = wp_insert_term(
                    $data['name'],
                    'product_cat',
                    $args
                );

                if (is_wp_error($term)) {
                    $existing = get_term_by('name', $data['name'], 'product_cat');
                    return $existing ? $existing->term_id : null;
                }

                return $term['term_id'];
            });

            $this->eventToProduct = new CHS_Sync('product');
            $this->eventPackageToVariation = new CHS_Sync('product_variation');
            $this->simpleEventPackageToProduct = new CHS_Sync('simple_product');
            $this->venueToLocationSync = new CHS_Sync('venue', true, function ($source_id) {
                $venue = $this->api->get("venues/$source_id");
                return $venue->data->name ?? null;
            });
        }

        /**
         * Syncs a page of events
         * @param int $page  The page number
         * @param int $limit The number of events per page
         * @return int The number of pages of events
         */
        public function syncEventPage(int $page, int $limit = 2) {
            $response = $this->fetchEvents($page, $limit);
            foreach ($response->data as $event) {
                $this->syncEvent($event->id, $event);
            }
            return ceil($response->total / $limit);
        }


        public function syncEvent($source_id, $event, $type = 'created') {
            $wcProductId = $this->eventToProduct->get($source_id);
            $wcProduct = wc_get_product($wcProductId);

            if ($type === 'deleted') {                
                if ($wcProduct) {
                    $wcProduct->delete(true); //(skip trash)
                    // Remove from the mapping table
                    $this->eventToProduct->delete($source_id);
                }
                
                return true;
            }
            
            // Check if the product exists in WooCommerce
            if (!$wcProduct) {
                if ($wcProductId) {
                    // Product was deleted, remove the mapping
                    $this->eventToProduct->delete($source_id);
                    // error_log('wcProductId was deleted: ' . $source_id);
                }
                
                // Create a new product based on simple flag
                if (isset($event->simple) && $event->simple == 1) {
                    $wcProduct = new WC_Product_Simple();
                } else {
                    $wcProduct = new WC_Product_Variable();
                }
            } 
        
            // Set basic product data
            if (isset($event->name)) {
                $wcProduct->set_name($event->name);
            }
            
            if (isset($event->active)) {
                $status = $event->active ? 'publish' : 'private';
                $wcProduct->set_status($status);
            }

            $wcProduct->save();
            
            // Handle categories and event types
            $categoryIds = [];
            $eventTypeIds = [];

            // Handle event type hierarchy
            if (isset($event->event_type_id)) {
                $eventTypeIds = $this->syncEventTypeHierarchy($event->event_type_id);
                if (!empty($eventTypeIds)) {
                    $validTermIds = [];
                    foreach ($eventTypeIds as $termId) {
                        $termId = intval($termId);
                        $term = get_term($termId, 'event_type');
                        if ($term && !is_wp_error($term)) {
                            $validTermIds[] = $termId;
                        }
                    }

                    if (!empty($validTermIds)) {
                        wp_set_object_terms($wcProduct->get_id(), $validTermIds, 'event_type');
                    }
                }

                //if not a teamsport event set the category to the event type instead of clubs
                if ((!isset($event->home_team_id) || empty($event->home_team_id)) && 
                (!isset($event->away_team_id) || empty($event->away_team_id))) {
                    $eventType = $this->api->get("event-types/" . $event->event_type_id);
                    if ($eventType && isset($eventType->data) && isset($eventType->data->name)) {
                        // Simply create or get the category with the event type name
                        $existingTerm = get_term_by('name', $eventType->data->name, 'product_cat');
                        if ($existingTerm && !is_wp_error($existingTerm)) {
                            $categoryIds[] = $existingTerm->term_id;
                        } else {
                            // Create new category without worrying about parent
                            $termArgs = [
                                'description' => '',
                                'slug' => sanitize_title($eventType->data->name)
                            ];
                            $term = wp_insert_term($eventType->data->name, 'product_cat', $termArgs);
                            if (!is_wp_error($term)) {
                                $categoryIds[] = $term['term_id'];
                            }
                        }
                    }
                }
            }



            // Handle team/club categories with parent event type as parent
            if (isset($event->home_team_id)) {
                $categoryIds[] = $this->syncClubCategory($event->home_team_id, $event->event_type_id);
            }

            if (!empty($categoryIds)) {
                $wcProduct->set_category_ids($categoryIds);
            }

            // Save the main product
            $wcProduct->save();

            
            // Sync custom fields using ACF
            if (isset($event->starts_at)) {
                $eventDateTime = new DateTime($event->starts_at);

                update_field('event_date', $eventDateTime->format('Ymd'), $wcProduct->get_id());
                update_field('event_time', $eventDateTime->format('H:i:s'), $wcProduct->get_id());
            }
            if (isset($event->venue_id)) {
                $eventLocation = $this->venueToLocationSync->sync($event->venue_id);
                update_field('event_location', $eventLocation, $wcProduct->get_id());
            }
            
            // Store the mapping
            $this->eventToProduct->set($source_id, $wcProduct->get_id());
        
            // For simple products, set price and stock from first event package
            if (isset($event->simple) && $event->simple == 1) {
                if(empty($event->event_packages)){
                    $event = $this->api->get("events/{$source_id}", ['with' => 'event_packages'])->data;
                }
                if (isset($event->event_packages) && !empty($event->event_packages)) {
                    $package = $event->event_packages[0];  // Get first package
                    
                    // Set price from package
                    if (isset($package->net_price)) {
                        $price = is_numeric($package->net_price) ? floatval($package->net_price) : 0;
                        $wcProduct->set_regular_price(number_format($price, 3, '.', ''));
                    }
                    
                    // Set stock from package
                    if (isset($package->available_stock)) {
                        $stock = is_numeric($package->available_stock) ? intval($package->available_stock) : 0;
                        $wcProduct->set_manage_stock(true);
                        $wcProduct->set_stock_quantity($stock);
                        $wcProduct->set_stock_status($stock <= 0 ? 'outofstock' : 'instock');
                    }
                    
                    // Save the simple product with updated data
                    $wcProduct->save();
                    
                    // Store the package mapping
                    if (isset($package->id)) {
                        $this->simpleEventPackageToProduct->set($package->id, $wcProduct->get_id());
                    }
                }
            } else {
                // For variable products, sync event packages as variations
                if (!empty($event->event_packages) && isset($event->event_packages) && is_array($event->event_packages)) {
                    $this->syncEventPackages($wcProduct, $event->event_packages);
                }
            }
            
            return $wcProduct;
        }

        /**
         * Syncs event type and its parent hierarchy
         */
        private function syncEventTypeHierarchy($eventTypeId) {
            $eventTypeIds = [];

            try {
                $eventType = $this->api->get("event-types/$eventTypeId");
                if (!$eventType || !isset($eventType->data)) {
                    return $eventTypeIds;
                }

                // If has parent, sync parent first
                if (isset($eventType->data->parent_event_type_id) && $eventType->data->parent_event_type_id) {
                    $parentIds = $this->syncEventTypeHierarchy($eventType->data->parent_event_type_id);
                    $eventTypeIds = array_merge($eventTypeIds, $parentIds);
                    // Get mapped parent term ID
                    $parent_term_id = $this->eventTypeToCategorySync->get($eventType->data->parent_event_type_id);
                }

                // Create or get current event type
                $termId = $this->eventTypeToCategorySync->sync($eventTypeId, [
                    'name' => $eventType->data->name,
                    'parent_id' => $parent_term_id ?? 0
                ]);

                if ($termId) {
                    $eventTypeIds[] = $termId;
                }

                return $eventTypeIds;
            } catch (Exception $e) {
                error_log('Error syncing event type hierarchy: ' . $e->getMessage());
                return $eventTypeIds;
            }
        }

        /**
         * Syncs club as category with optional event type parent
         */
        private function syncClubCategory($clubId, $eventTypeId = null) {
            try {
                $club = $this->api->get("clubs/$clubId");
                if (!$club || !isset($club->data)) {
                    return null;
                }

                // Find or determine parent category
                $parentId = null;
                if ($eventTypeId) {
                    $eventType = $this->api->get("event-types/$eventTypeId");
                    if ($eventType && isset($eventType->data)) {
                        // If event type has parent, use that
                        if (isset($eventType->data->parent_event_type_id) && $eventType->data->parent_event_type_id) {
                            // Get parent event type details
                            $parentType = $this->api->get("event-types/" . $eventType->data->parent_event_type_id);
                            if ($parentType && isset($parentType->data)) {
                                // Look for existing category with parent's name
                                $parentTerm = get_term_by('name', $parentType->data->name, 'product_cat');
                                if ($parentTerm) {
                                    $parentId = $parentTerm->term_id;
                                }
                            }
                        }
                    }
                }

                // Look for existing club category
                $existing = get_term_by('name', $club->data->name, 'product_cat');
                if ($existing) {
                    // Update parent if needed
                    if ($parentId) {
                        wp_update_term($existing->term_id, 'product_cat', [
                            'parent' => $parentId
                        ]);
                    }
                    return $existing->term_id;
                }

                // Create new club category under parent
                $args = [
                    'description' => '',
                    'slug' => sanitize_title($club->data->name),
                    'parent' => $parentId
                ];

                $term = wp_insert_term($club->data->name, 'product_cat', $args);
                return !is_wp_error($term) ? $term['term_id'] : null;

            } catch (Exception $e) {
                error_log('Error syncing club category: ' . $e->getMessage());
                return null;
            }
        }

        private function createOrUpdatePackageDetail($package_id) {
            // Get full package details including related data
            $packageDetails = $this->api->get("packages/{$package_id}", [
                'with' => 'informations,inclusions,gallery'
            ]);

            if (!$packageDetails || empty($packageDetails->data->name)) {
                // error_log("Failed to get package details for ID: {$package_id}");
                return false;
            }

            $packageName = trim($packageDetails->data->name);

            // Check if package detail post already exists by name
            $existing_posts = get_posts(array(
                'post_type' => 'package-details',
                'title' => $packageName,
                'post_status' => 'publish',
                'posts_per_page' => 1
            ));

            if (!empty($existing_posts)) {
                $post_id = $existing_posts[0]->ID;
                // Update existing post
                $post_data = array(
                    'ID' => $post_id,
                    'post_title' => $packageName,
                    'post_status' => 'publish',
                );
                wp_update_post($post_data);
            } else {
                // Create new package detail post
                $post_data = array(
                    'post_title' => $packageName,
                    'post_status' => 'publish',
                    'post_type' => 'package-details'
                );

                $post_id = wp_insert_post($post_data);
            }

            if ($post_id && !is_wp_error($post_id)) {
                // Update basic ACF fields
                update_field('package_description', $packageDetails->data->description ?? '', $post_id);
                update_field('package_minimum_qty', 1, $post_id);
                update_field('package_unit', 'Per Person', $post_id);

                // Update FAQ's Listing Text with day_info
                if (isset($packageDetails->data->day_info)) {
                    update_field('faqs_listing_text', $packageDetails->data->day_info, $post_id);
                }

                // Handle inclusions in the Package Hospitality repeater field
                $hospitality_rows = [];

                // Add inclusions if they exist
                if (isset($packageDetails->data->inclusions) && is_object($packageDetails->data->inclusions)) {
                    foreach ($packageDetails->data->inclusions as $title => $description) {
                        $hospitality_rows[] = array(
                            'icon' => $this->getIconByName($title),
                            'bullet_title' => $title,
                            'bullet_text' => $description
                        );
                    }
                }

                // Add informations if they exist
                if (isset($packageDetails->data->informations) && is_object($packageDetails->data->informations)) {
                    foreach ($packageDetails->data->informations as $title => $description) {
                        $hospitality_rows[] = array(
                            'icon' => $this->getIconByName($title),
                            'bullet_title' => $title,
                            'bullet_text' => $description
                        );
                    }
                }

                // Update the repeater field with all rows
                if (!empty($hospitality_rows)) {
                    update_field('package_hospitality', $hospitality_rows, $post_id);
                }

                // Handle seating plan image
                if (isset($packageDetails->data->seating_plan) && !empty($packageDetails->data->seating_plan)) {
                    // Check if there's already a seating plan image
                    $existing_seating_plan = get_field('package_seated_file', $post_id);

                    if (!$existing_seating_plan) {
                        $seating_plan = $packageDetails->data->seating_plan;

                        if (isset($seating_plan->url) && !empty($seating_plan->url)) {
                            $attachment_id = $this->uploadImageFromUrl($seating_plan->url, $post_id);

                            if ($attachment_id) {
                                // Set the image title/alt if needed
                                if (!empty($seating_plan->name)) {
                                    wp_update_post(array(
                                        'ID' => $attachment_id,
                                        'post_title' => $seating_plan->name,
                                    ));
                                }

                                update_field('package_seated_file', $attachment_id, $post_id);
                            }
                        }
                    }
                }

                if (isset($packageDetails->data->gallery) && is_array($packageDetails->data->gallery)) {
                    $this->handleGalleryImages($post_id, $packageDetails->data->gallery);
                }

                return $post_id;
            }

            return false;
        }

        private function getIconByName($name) {
            $name = strtolower($name);

            $iconMapping = [
                'football' => 'football.svg',
                'dining' => 'food.svg',
                'drinks' => 'drinks2.svg',
                'clock' => 'clock1.svg',
                'program' => 'book2.svg',
                'pin' => 'map2.svg'
            ];

            foreach ($iconMapping as $key => $icon) {
                if (strpos($name, $key) !== false) {
                    return $icon;
                }
            }

            // Default to Other icon
            return 'plus1-icon.svg';
        }


        protected function syncEventPackages($wcProduct, $eventPackages) {
            $attributeTaxonomyName = 'pa_packages';
            // Check if attribute exists in WooCommerce
            $attribute_id = wc_attribute_taxonomy_id_by_name('packages');
            if (!$attribute_id) {
                $attribute_id = $this->createPackageAttribute();
            }

            // Get or create terms for packages
            $packageTerms = [];
            $allTermSlugs = [];

            foreach ($eventPackages as $package) {
                $packageDetails = $this->api->get("packages/{$package->package_id}");
                if (!$packageDetails || empty($packageDetails->data->name)) {
                    continue;
                }

                $displayName = trim($packageDetails->data->name);
                $termSlug = sanitize_title($displayName);

                // First try to find existing term by slug
                $term = get_term_by('slug', $termSlug, $attributeTaxonomyName);

                // If not found by slug, try by name
                if (!$term) {
                    $term = get_term_by('name', $displayName, $attributeTaxonomyName);
                }

                // If still no term, create it with proper display name
                if (!$term) {
                    $termResult = wp_insert_term(
                        $displayName,
                        $attributeTaxonomyName,
                        array('slug' => $termSlug)
                    );

                    if (is_wp_error($termResult)) {
                        // error_log('Error creating term: ' . $termResult->get_error_message());
                        continue;
                    }

                    $term = get_term($termResult['term_id'], $attributeTaxonomyName);
                }

                if ($term && !is_wp_error($term)) {
                    $packageTerms[$package->id] = $term;
                    $allTermSlugs[] = $term->slug;
                }
            }

            if (!empty($allTermSlugs)) {
                // Use term names for attribute options
                $termNames = array_map(function ($term) {
                    return $term->name;
                }, $packageTerms);

                // Get existing product attributes
                $existing_attributes = $wcProduct->get_attributes();

                // Create WC_Product_Attribute object
                $attribute = new WC_Product_Attribute();
                $attribute->set_id($attribute_id);
                $attribute->set_name($attributeTaxonomyName);
                $attribute->set_options($termNames);
                $attribute->set_position(0);
                $attribute->set_visible(true);
                $attribute->set_variation(true);

                // Merge with existing attributes
                $existing_attributes[$attributeTaxonomyName] = $attribute;
                $wcProduct->set_attributes($existing_attributes);

                try {
                    $wcProduct->save();

                    // Delete existing variations
                    $existing_variations = $wcProduct->get_children();
                    foreach ($existing_variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation) {
                            $variation->delete(true); // true means force delete (skip trash)
                        }
                        
                        // ProductVariation was deleted, remove the mapping
                        $this->eventPackageToVariation->delete($variation_id, true);   
                        // error_log('variation_id was deleted: ' . $variation_id);
                    }
                    
                    // Create variations with package detail links
                    foreach ($eventPackages as $package) {
                        // Create or update package detail post
                        $packageDetailId = $this->createOrUpdatePackageDetail($package->package_id);

                        if (!isset($packageTerms[$package->id])) {
                            continue;
                        }

                        $variation = new WC_Product_Variation();
                        $variation->set_parent_id($wcProduct->get_id());

                        $variation->set_attributes(array(
                            'pa_packages' => $packageTerms[$package->id]->slug
                        ));

                        $price = is_numeric($package->net_price) ? floatval($package->net_price) : 0;
                        $variation->set_regular_price(number_format($price, 3, '.', ''));

                        $stock = is_numeric($package->available_stock) ? intval($package->available_stock) : 0;
                        $variation->set_manage_stock(true);
                        $variation->set_stock_quantity($stock);
                        $variation->set_stock_status($stock <= 0 ? 'outofstock' : 'instock');

                        $variation_id = $variation->save();

                        if ($variation_id) {
                            $this->eventPackageToVariation->set($package->id, $variation_id);

                            // Link to package detail                
                            if ($packageDetailId) {
                                update_post_meta($variation_id, 'associated_package', $packageDetailId);
                            }

                            update_post_meta($variation_id, "attribute_$attributeTaxonomyName", $packageTerms[$package->id]->slug);
                        }
                    }

                    // Update parent product prices
                    $variation_prices = array();
                    $variations = $wcProduct->get_children();
                    foreach ($variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation) {
                            $price = $variation->get_regular_price('edit');
                            if (is_numeric($price)) {
                                $variation_prices[] = floatval($price);
                            }
                        }
                    }

                    if (!empty($variation_prices)) {
                        $min_price = min($variation_prices);
                        $max_price = max($variation_prices);

                        update_post_meta($wcProduct->get_id(), '_price', $min_price);
                        update_post_meta($wcProduct->get_id(), '_regular_price', $min_price);

                        if ($min_price !== $max_price) {
                            update_post_meta($wcProduct->get_id(), '_min_variation_price', $min_price);
                            update_post_meta($wcProduct->get_id(), '_max_variation_price', $max_price);
                            update_post_meta($wcProduct->get_id(), '_min_variation_regular_price', $min_price);
                            update_post_meta($wcProduct->get_id(), '_max_variation_regular_price', $max_price);
                        }
                    }

                    // Force sync of prices
                    WC_Product_Variable::sync($wcProduct->get_id());
                    wc_delete_product_transients($wcProduct->get_id());

                } catch (Exception $e) {
                    error_log('Error saving product variations: ' . $e->getMessage());
                }
            }
        }

        private function createPackageAttribute()
        {
            $attribute = array(
                'name' => 'Packages',
                'slug' => 'packages',
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            );

            $attribute_id = wc_create_attribute($attribute);

            if (!is_wp_error($attribute_id)) {
                register_taxonomy(
                    'pa_packages',
                    'product',
                    array(
                        'hierarchical' => false,
                        'show_ui' => true,
                        'query_var' => true,
                        'rewrite' => array('slug' => 'package'),
                    )
                );

                if (!defined('REST_REQUEST')) {
                    flush_rewrite_rules();
                }
            }

            return $attribute_id;
        }

        private function getOrCreatePackageTerm($displayName, $attributeTaxonomyName)
        {
            $termSlug = sanitize_title($displayName);

            // Try to find existing term
            $term = get_term_by('slug', $termSlug, $attributeTaxonomyName);
            if (!$term) {
                $term = get_term_by('name', $displayName, $attributeTaxonomyName);
            }

            if (!$term) {
                $termResult = wp_insert_term(
                    $displayName,
                    $attributeTaxonomyName,
                    array('slug' => $termSlug)
                );

                if (!is_wp_error($termResult)) {
                    $term = get_term($termResult['term_id'], $attributeTaxonomyName);
                }
            }

            return $term;
        }
        public function syncEventPackage($source_id, $eventPackage, $type = 'created')
        {


            if ($type === 'deleted') {
                // Get the variation ID from our mapping
                $variationId = $this->eventPackageToVariation->get($source_id);
                
                if ($variationId) {
                    $variation = wc_get_product($variationId);
                    
                    if ($variation && $variation instanceof WC_Product_Variation) {
                        // Get the parent product ID before deleting the variation
                        $parentId = $variation->get_parent_id();
                        
                        // Delete the variation using WooCommerce's method
                        $variation->delete(true); // true means force delete (skip trash)
                        
                        // Remove from the mapping table
                        $this->eventPackageToVariation->delete($source_id);
                        
                        // Update parent product prices and clear caches
                        if ($parentId) {
                            $this->updateParentPrices($parentId);
                            wc_delete_product_transients($parentId);
                        }
                        
                        return true;
                    }
                }
            
                // If we get here, no variation or simple product was found
                // error_log("Could not find variation or simple product for deleted event package: {$source_id}");
                return false;
            }
            $simpleProductId = $this->simpleEventPackageToProduct->get($source_id);
            if(!empty($simpleProductId)){
                $wcProduct = wc_get_product($simpleProductId);
                // Set price from package
                if (isset($eventPackage->net_price)) {
                    $price = is_numeric($eventPackage->net_price) ? floatval($eventPackage->net_price) : 0;
                    $wcProduct->set_regular_price(number_format($price, 3, '.', ''));
                }
                
                // Set stock from package
                if (isset($eventPackage->available_stock)) {
                    $stock = is_numeric($eventPackage->available_stock) ? intval($eventPackage->available_stock) : 0;
                    $wcProduct->set_manage_stock(true);
                    $wcProduct->set_stock_quantity($stock);
                    $wcProduct->set_stock_status($stock <= 0 ? 'outofstock' : 'instock');
                }
                
                // Save the simple product with updated data
                $wcProduct->save();
                return;
            }

            // error_log("Starting syncEventPackage for source_id: {$source_id}");

            // Get parent product and validate it exists and is variable
            $parentId = isset($eventPackage->event_id) ? $this->eventToProduct->get($eventPackage->event_id) : null;

            // If no parent found via lookup, check if we already have this variation
           if (!$parentId) {
               // See if we have this variation already
               $variationId = $this->eventPackageToVariation->get($source_id);  
               
               if ($variationId) {
                   $variation = wc_get_product($variationId);
                   if ($variation && $variation instanceof WC_Product_Variation) {
                       // Get parent ID from the variation
                       $parentId = $variation->get_parent_id();
                       // error_log("Found parent product ID {$parentId} from existing variation");
                       
                       // Optionally update the lookup table if we found a missing mapping
                       if (isset($eventPackage->event_id)) {
                           $this->eventToProduct->set($eventPackage->event_id, $parentId);
                           // error_log("Updated missing mapping for event_id {$eventPackage->event_id}");
                       }
                   }
               }
           }
            if (!$parentId) {
                // error_log('Cannot sync event package: No parent product found ' . $eventPackage->event_id);
                echo 'Cannot sync event package: No parent product found ' . $eventPackage->event_id;
                http_response_code(500);
                return null;
            }

            $wcProduct = wc_get_product($parentId);
            if (!$wcProduct || !$wcProduct instanceof WC_Product_Variable) {
                // error_log('Parent product is not a variable product');
                echo 'Parent product is not a variable product';
                http_response_code(500);
                return null;
            }
            // Setup the package attribute taxonomy
            $attributeTaxonomyName = 'pa_packages';
            $attribute_id = wc_attribute_taxonomy_id_by_name('packages');
            if (!$attribute_id) {
                // error_log("Creating new package attribute");
                $attribute_id = $this->createPackageAttribute();
            }

            // If its a simple package or its an update and package_id was not changed
            if(!empty($eventPackage->package_id)){
                
                // Get or create the term for this package
                $packageDetails = $this->api->get("packages/{$eventPackage->package_id}");
                if (!$packageDetails || empty($packageDetails->data->name)) {
                    // error_log("Failed to get package details");
                    echo 'Failed to get package details';
                    http_response_code(500);
                    return null;
                }

                $displayName = trim($packageDetails->data->name);
                $termSlug = sanitize_title($displayName);

                // error_log("Processing package: {$displayName} (slug: {$termSlug})");

                // Get or create the term
                $term = get_term_by('slug', $termSlug, $attributeTaxonomyName);
                if (!$term) {
                    $termResult = wp_insert_term(
                        $displayName,
                        $attributeTaxonomyName,
                        array('slug' => $termSlug)
                    );

                    if (is_wp_error($termResult)) {
                        // error_log("Error creating term: " . $termResult->get_error_message());
                        echo "Error creating term: " . $termResult->get_error_message();
                        http_response_code(500);
                        return null;
                    }

                    $term = get_term($termResult['term_id'], $attributeTaxonomyName);
                }

                if (!$term || is_wp_error($term)) {
                    // error_log("Failed to get/create term");
                    http_response_code(500);
                    return null;
                }
            }
            
        if (!empty($eventPackage->package_id)) {
            // Get existing product attributes
            $product_attributes = $wcProduct->get_attributes();

            if (!isset($product_attributes[$attributeTaxonomyName])) {
                // error_log("Creating new attribute for product");
                $attribute = new WC_Product_Attribute();
                $attribute->set_id($attribute_id);
                $attribute->set_name($attributeTaxonomyName);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $existing_term_ids = array();
            } else {
                // error_log("Using existing attribute");
                $attribute = $product_attributes[$attributeTaxonomyName];
                $existing_term_ids = $attribute->get_options();
            }

            // Add new term ID if it doesn't exist
            if (!in_array($term->term_id, $existing_term_ids)) {
                // error_log("Adding new term ID: " . $term->term_id);
                $existing_term_ids[] = $term->term_id;
            }

            // Important: Sort and make unique
            $existing_term_ids = array_unique(array_map('absint', $existing_term_ids));
            sort($existing_term_ids);

            // error_log("Setting term IDs: " . print_r($existing_term_ids, true));

            // Set both the attribute options AND the object terms
            $attribute->set_options($existing_term_ids);
            $product_attributes[$attributeTaxonomyName] = $attribute;
            $wcProduct->set_attributes($product_attributes);
        
            // Save product and explicitly set object terms
            $wcProduct->save();
            wp_set_object_terms($wcProduct->get_id(), $existing_term_ids, $attributeTaxonomyName);
        
        }
            $variationId = $this->eventPackageToVariation->get($source_id);
            $variation = $variationId ? wc_get_product($variationId) : new WC_Product_Variation();
            
            if (!$variation->get_id()) {
                error_log("Creating new variation");
                $variation->set_parent_id($wcProduct->get_id());
                
                // Only set variation attributes when creating a new variation
                if (!empty($termSlug)) {
                    $variation->set_attributes(array(
                        'pa_packages' => $termSlug
                    ));
                }
            }
            
            // Set variation data
            if (isset($eventPackage->net_price)) {
                $price = is_numeric($eventPackage->net_price) ? floatval($eventPackage->net_price) : 0;
                $variation->set_regular_price(number_format($price, 3, '.', ''));
            }
            
            if (isset($eventPackage->available_stock)) {
                $stock = is_numeric($eventPackage->available_stock) ? intval($eventPackage->available_stock) : 0;
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($stock);
                $variation->set_stock_status($stock <= 0 ? 'outofstock' : 'instock');
            }
            
            // Save variation and handle post-save tasks
            $variation_id = $variation->save();
            
            if ($variation_id) {
                error_log("Saved variation: {$variation_id}");
                $this->eventPackageToVariation->set($source_id, $variation_id);
                
                // Create or update package detail only when package_id is available
                if (!empty($eventPackage->package_id)) {
                    $packageDetailId = $this->createOrUpdatePackageDetail($eventPackage->package_id);
                    if ($packageDetailId) {
                        update_post_meta($variation_id, 'associated_package', $packageDetailId);
                    }
                    
                    // Set variation attribute metadata only when termSlug is available
                    if (!empty($termSlug)) {
                        update_post_meta($variation_id, "attribute_{$attributeTaxonomyName}", $termSlug);
                    }
                }
            }
            
            // Update prices and clear caches
            $this->updateParentPrices($wcProduct->get_id());
            wc_delete_product_transients($wcProduct->get_id());
            
            return $variation;
        }
        private function updateParentPrices($product_id)
        {
            $wcProduct = wc_get_product($product_id);
            if (!$wcProduct)
                return;

            $variation_prices = array();
            $variations = $wcProduct->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $price = $variation->get_regular_price('edit');
                    if (is_numeric($price)) {
                        $variation_prices[] = floatval($price);
                    }
                }
            }

            if (!empty($variation_prices)) {
                $min_price = min($variation_prices);
                $max_price = max($variation_prices);

                update_post_meta($product_id, '_price', $min_price);
                update_post_meta($product_id, '_regular_price', $min_price);

                if ($min_price !== $max_price) {
                    update_post_meta($product_id, '_min_variation_price', $min_price);
                    update_post_meta($product_id, '_max_variation_price', $max_price);
                    update_post_meta($product_id, '_min_variation_regular_price', $min_price);
                    update_post_meta($product_id, '_max_variation_regular_price', $max_price);
                }
            }

            WC_Product_Variable::sync($product_id);
            wc_delete_product_transients($product_id);
        }

        // Helper method to update lookup tables
        private function update_lookup_tables($product_id)
        {
            global $wpdb;

            // Only run if WC_Install exists and we're not in an API request
            if (class_exists('WC_Install') && !defined('REST_REQUEST')) {
                // Update product lookup tables
                $tables = array(
                    'wc_product_meta_lookup',
                    'wc_product_attributes_lookup'
                );

                foreach ($tables as $table) {
                    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$table}'") === $wpdb->prefix . $table) {
                        wc_update_product_lookup_tables_column($product_id, $table);
                    }
                }
            }
        }

        public function fetchEvents($page = 0, $limit = 10)
        {
            return $this->api->get('events', [
                'page' => $page,
                'limit' => $limit,
                'active' => 0,
                'starts_at' => date('c'),
                'with' => 'event_packages' // Include event packages
            ]);
        }

        protected function createCategory($name, $description, $parentId = null)
        {
            $term = wp_insert_term(
                $name,
                'product_cat',
                [
                    'description' => $description,
                    'slug' => sanitize_title($name),
                ]
            );

            if (is_wp_error($term)) {
                return null;
            }

            return $term['term_id'];
        }

        private function handleGalleryImages($post_id, $gallery_images)
        {
            if (!is_array($gallery_images) || empty($gallery_images)) {
                return;
            }

            // Get existing gallery
            $existing_gallery = get_field('package_gallery', $post_id);
            $existing_gallery = is_array($existing_gallery) ? $existing_gallery : array();

            // Create a map of existing image names for quick lookup
            $existing_image_names = array();
            foreach ($existing_gallery as $image) {
                if (isset($image['filename'])) {
                    $existing_image_names[$image['filename']] = $image['ID'];
                }
            }

            $new_gallery_ids = array();

            foreach ($gallery_images as $image) {
                $filename = basename($image->file_name);

                // Check if we already have this image
                if (isset($existing_image_names[$filename])) {
                    // Keep existing image
                    $new_gallery_ids[] = $existing_image_names[$filename];
                    continue;
                }

                // Image doesn't exist, upload it
                if (isset($image->url) && !empty($image->url)) {
                    $attachment_id = $this->uploadImageFromUrl($image->url, $post_id);

                    if ($attachment_id) {
                        // Update attachment title if needed
                        if (!empty($image->name)) {
                            wp_update_post(array(
                                'ID' => $attachment_id,
                                'post_title' => $image->name,
                            ));
                        }

                        $new_gallery_ids[] = $attachment_id;
                    }
                }
            }

            // Update the gallery field if we have images
            if (!empty($new_gallery_ids)) {
                update_field('package_gallery', $new_gallery_ids, $post_id);
            }
        }
        private function uploadImageFromUrl($image_url, $post_id)
        {
            // Basic URL validation
            if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                error_log("Invalid image URL provided: {$image_url}");
                return false;
            }

            // Check if URL is accessible
            $response = wp_remote_head($image_url, [
                'timeout' => 5,
                'sslverify' => false // For local development
            ]);

            // Check if request was successful and is an image
            if (is_wp_error($response)) {
                error_log("Image URL not accessible: " . $response->get_error_message());
                return false;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                error_log("Image URL returned non-200 status code: {$response_code}");
                return false;
            }

            // Verify it's an image from content type
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            if (!$content_type || strpos($content_type, 'image/') !== 0) {
                error_log("URL does not point to an image: {$content_type}");
                return false;
            }

            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            // Download file to temp dir with a timeout
            $temp_file = download_url($image_url, 30);

            if (is_wp_error($temp_file)) {
                error_log('Failed to download image: ' . $temp_file->get_error_message());
                return false;
            }

            // Get the filename and extension from URL, fallback to generating one
            $filename = basename(parse_url($image_url, PHP_URL_PATH));
            if (empty($filename)) {
                // Generate filename based on content type
                $extension = $this->get_extension_from_content_type($content_type);
                $filename = md5($image_url) . $extension;
            }

            // Prepare file array for media_handle_sideload
            $file_array = array(
                'name' => $filename,
                'tmp_name' => $temp_file
            );

            // Handle the upload
            $attachment_id = media_handle_sideload($file_array, $post_id);

            // Clean up temp file
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }

            if (is_wp_error($attachment_id)) {
                error_log('Failed to upload image: ' . $attachment_id->get_error_message());
                return false;
            }

            return $attachment_id;
        }

        /**
         * Helper function to get file extension from content type
         */
        private function get_extension_from_content_type($content_type)
        {
            $map = [
                'image/jpeg' => '.jpg',
                'image/jpg' => '.jpg',
                'image/png' => '.png',
                'image/gif' => '.gif',
                'image/webp' => '.webp',
                'image/svg+xml' => '.svg'
            ];

            return isset($map[$content_type]) ? $map[$content_type] : '.jpg';
        }


    }
}
