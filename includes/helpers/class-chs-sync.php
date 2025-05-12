<?php

class CHS_Sync
{
    private $model_name;
    private $cache = [];
    private $callback;
    private $table_name;
    private $reverse_lookup;

    public function __construct($model_name, $prefetch = false, callable $callback = null, $reverse_lookup = false)
    {
        global $wpdb;
        $this->model_name = $model_name;
        $this->callback = $callback;
        $this->table_name = $wpdb->prefix . 'chs_sync';
        $this->reverse_lookup = $reverse_lookup;

        if ($prefetch) {
            $this->prefetch();
        }
    }

    private function prefetch()
    {
        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT source_id, target_id FROM {$this->table_name} WHERE model_name = %s",
            $this->model_name
        ));

        foreach ($results as $row) {
            if ($this->reverse_lookup) {
                $this->cache[$row->target_id] = $row->source_id;
            } else {
                $this->cache[$row->source_id] = $row->target_id;
            }
        }
    }

    public function get($id)
    {
        if (isset($this->cache[$id])) {
            $result = $this->cache[$id];
            return is_numeric($result) ? intval($result) : $result;
        }

        global $wpdb;
        $column = $this->reverse_lookup ? 'target_id' : 'source_id';
        $opposite_column = $this->reverse_lookup ? 'source_id' : 'target_id';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT $opposite_column FROM {$this->table_name} WHERE model_name = %s AND $column = %s",
            $this->model_name,
            $id
        ));

        if ($result !== null) {
            $this->cache[$id] = $result;
            return is_numeric($result) ? intval($result) : $result;
        }

        return null;
    }

    public function set($source_id, $target_id, $skipCheck = false)
    {
        $lookup_id = $this->reverse_lookup ? $target_id : $source_id;
        if (!$skipCheck && $this->get($lookup_id) !== null) {
            return;
        }
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            [
                'model_name' => $this->model_name,
                'source_id' => $source_id,
                'target_id' => $target_id
            ],
            ['%s', '%d', '%s']
        );

        $this->cache[$lookup_id] = $this->reverse_lookup ? $source_id : $target_id;
    }

    public function sync($source_id, $extraIdentifier = null)
    {
        $target_id = $this->get($source_id);

        if ($target_id === null && $this->callback !== null) {
            $target_id = call_user_func($this->callback, $source_id, $extraIdentifier);
            if ($target_id !== null) {
                $this->set($source_id, $target_id, true);
            }
        }

        return $target_id;
    }

    /**
     * Deletes a mapping from the database and cache
     * @param mixed $id The ID of the source or target depending on lookup direction
     * @return void
     */
    public function delete($id, $overriteReverseLookup = null)
    {
        global $wpdb;
        $reverseLookup = $overriteReverseLookup ?? $this->reverse_lookup;

        $column = $reverseLookup ? 'target_id' : 'source_id';


        if ($overriteReverseLookup !== null) {
            $opposite_column = $reverseLookup ? 'source_id' : 'target_id';

            // Find the corresponding ID before deleting
            $corresponding_id = $wpdb->get_var($wpdb->prepare(
                "SELECT {$opposite_column} FROM {$this->table_name} 
            WHERE model_name = %s AND {$column} = %s",
                $this->model_name,
                $id
            ));
            unset($this->cache[$corresponding_id]);
        } else {
            unset($this->cache[$id]);
        }

        $wpdb->delete(
            $this->table_name,
            [
                'model_name' => $this->model_name,
                $column => $id
            ],
            ['%s', '%d']
        );

    }
}