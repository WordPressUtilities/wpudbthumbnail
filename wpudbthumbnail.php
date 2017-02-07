<?php

/*
Plugin Name: WPU DB Thumbnail
Description: Store a small thumbnail in db
Version: 0.5
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpudbthumbnail {

    private $meta_id = 'wpudbthumbnail_base64thumb';
    private $jpeg_quality = 30;
    private $image_size = 10;
    private $post_types = 'any';

    public function __construct() {
        add_action('init', array(&$this, 'init'));
        add_action('added_post_meta', array(&$this, 'update_post_meta'), 10, 4);
        add_action('updated_postmeta', array(&$this, 'update_post_meta'), 10, 4);
        add_action('deleted_post_meta', array(&$this, 'deleted_post_meta'), 10, 4);
    }

    public function init() {
        $this->jpeg_quality = apply_filters('wpudbthumbnail_jpegquality', $this->jpeg_quality);
        $this->image_size = apply_filters('wpudbthumbnail_imagesize', $this->image_size);
        $this->post_types = apply_filters('wpudbthumbnail_posttypes', $this->post_types);
    }

    /* Triggered when _thumbnail_id is modified */
    public function update_post_meta($meta_id, $object_id, $meta_key, $meta_value) {
        if ($meta_key != '_thumbnail_id') {
            return false;
        }
        $this->save_post($object_id);
    }

    public function deleted_post_meta($meta_ids, $object_id, $meta_key, $_meta_value) {
        if ($meta_key != '_thumbnail_id') {
            return false;
        }
        delete_post_meta($object_id, $this->meta_id);
        delete_post_meta($object_id, $this->meta_id . '_id');
    }

    /* Triggered when post is saved */
    public function save_post($post_id = false) {

        /* Invalid post */
        if (!is_numeric($post_id) || wp_is_post_revision($post_id) || 'trash' == get_post_status($post_id)) {
            return false;
        }

        /* Check if enabled for this post type */
        if ($this->post_types != 'any') {
            $post_type = get_post_type($post_id);
            if (is_array($this->post_types)) {
                /* User has set an array of post types */
                if (!in_array($post_type, $this->post_types)) {
                    return false;
                }
            } else {
                /* User has set just a post type in a string */
                if ($post_type != $this->post_types) {
                    return false;
                }
            }
        }

        /* Thumbnail do not exists */
        $post_thumbnail_id = get_post_thumbnail_id($post_id);
        if (!is_numeric($post_thumbnail_id)) {
            return false;
        }

        /* Same attachment is used */
        if (get_post_meta($post_id, $this->meta_id . '_id', 1) == $post_thumbnail_id) {
            return false;
        }

        /* Save as base64 */
        $base64 = $this->get_base64_thumb($post_thumbnail_id);

        if ($base64 !== false) {
            update_post_meta($post_id, $this->meta_id, $base64);
            update_post_meta($post_id, $this->meta_id . '_id', $post_thumbnail_id);
        }
    }

    public function get_base64_thumb($post_thumbnail_id = false) {

        if (!$post_thumbnail_id) {
            return false;
        }

        /* Retrieve image */
        $base_image = get_attached_file($post_thumbnail_id);
        $image = wp_get_image_editor($base_image);
        if (is_wp_error($image)) {
            return false;
        }

        /* Generate image */
        $type = pathinfo($base_image, PATHINFO_EXTENSION);
        $mime_type = 'image/' . $type;
        if ($type == 'jpg' || $type == 'jpeg') {
            $mime_type = 'image/jpeg';
            $type = 'jpg';
        }
        $upload_dir = wp_upload_dir();
        $image->resize($this->image_size, $this->image_size, false);
        if ($type == 'jpg') {
            $image->set_quality($this->jpeg_quality);
        }

        /* Save tmp image */
        $tmp_file = $image->generate_filename('tmp-thumb', $upload_dir['basedir'], $type);
        $image->save($tmp_file, $mime_type);
        $data = file_get_contents($tmp_file);
        unlink($tmp_file);

        /* Generate base64 string */
        $base64 = 'data:' . $mime_type . ';base64,' . base64_encode($data);

        return $base64;
    }

    /* ----------------------------------------------------------
      Uninstall
    ---------------------------------------------------------- */

    public function uninstall() {
        /* Delete used post metas */
        delete_post_meta_by_key($this->meta_id);
        delete_post_meta_by_key($this->meta_id . '_id');
    }

}

$wpudbthumbnail = new wpudbthumbnail();
