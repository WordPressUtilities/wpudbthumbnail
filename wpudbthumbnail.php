<?php

/*
Plugin Name: WPU DB Thumbnail
Description: Store a small thumbnail in db
Version: 0.9
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpudbthumbnail {

    private $meta_id = 'wpudbthumbnail_base64thumb';
    private $jpeg_quality = 30;
    private $image_size = 40;
    private $cache_in_file = false;
    private $post_types = 'any';
    private $jpeg_prefix = '';
    private $compress_base64 = true;
    private $debug = false;

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
        $this->cache_in_file = apply_filters('wpudbthumbnail_cacheinfile', $this->cache_in_file);
        $this->compress_base64 = apply_filters('wpudbthumbnail_compress_base64', $this->compress_base64);

        /* File cache settings */
        $upload_dir = wp_upload_dir();
        $this->cache_dir = apply_filters('wpudbthumbnail_cachedir', $upload_dir['basedir'] . '/wpudbthumbnail/');
        if ($this->cache_in_file && !is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755);
            @chmod($this->cache_dir, 0755);
            @file_put_contents($this->cache_dir . '.htaccess', 'deny from all');
        }

        /* Settings version */
        $settings_key = md5($this->jpeg_quality . $this->image_size . serialize($this->post_types) . serialize($this->compress_base64) . $this->cache_in_file);
        $settings_version = get_option('wpudbthumbnail_settingsversion');
        if ($settings_version != $settings_key) {
            update_option('wpudbthumbnail_settingsversion', $settings_key);
            $this->clear_cache();
            $this->update_jpeg_prefix();
        }

        $this->jpeg_prefix = get_option('wpudbthumbnail_jpegprefix');
        if (!$this->jpeg_prefix) {
            $this->update_jpeg_prefix();
        }

        if ($this->debug) {
            $this->clear_cache();
            $this->update_jpeg_prefix();
        }
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
    public function save_post($post_id = false, $reload = false) {

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
        if (!$reload && get_post_meta($post_id, $this->meta_id . '_id', 1) == $post_thumbnail_id) {
            return false;
        }

        /* Save as base64 */
        $base_image = get_attached_file($post_thumbnail_id);
        $base64 = $this->generate_base64_thumb($base_image);

        if ($this->compress_base64) {
            $base64 = str_replace('data:image/jpeg;base64,/9j/', '#d#', $base64);
            $base64 = str_replace($this->jpeg_prefix, '#j#', $base64);
            if ($this->cache_in_file && function_exists('gzencode')) {
                $base64 = gzencode($base64);
            }
        }

        if ($base64 !== false) {
            if ($this->cache_in_file) {
                $this->set_cachefilebase64($post_id, $base64);
            } else {
                update_post_meta($post_id, $this->meta_id, $base64);
            }
            update_post_meta($post_id, $this->meta_id . '_id', $post_thumbnail_id);
        }
    }

    public function generate_base64_thumb($base_image = false) {

        if (!$base_image) {
            return false;
        }

        /* Retrieve image */
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
        $image->resize($this->image_size, $this->image_size, 1);
        if ($type == 'jpg') {
            $image->set_quality($this->jpeg_quality);
        }

        /* Save tmp image */
        $tmp_file = $image->generate_filename('tmp-thumb', $upload_dir['basedir'], $type);
        $image->save($tmp_file, $mime_type);

        $this->compress_image_file($tmp_file, $type);

        /* Extract file content */
        $data = file_get_contents($tmp_file);
        unlink($tmp_file);

        /* Generate base64 string */
        $base64 = 'data:' . $mime_type . ';base64,' . base64_encode($data);

        return $base64;
    }

    public function compress_image_file($tmp_file, $type = 'jpg') {
        /* Remove image metas */
        if (class_exists('Imagick')) {
            $img = new Imagick($tmp_file);
            $img->stripImage();
            $img->writeImage($tmp_file);
            $img->clear();
            $img->destroy();
        } elseif (function_exists('gd_info')) {
            if ($type == 'jpg') {
                /* GD is installed and working */
                $img = imagecreatefromjpeg($tmp_file);
                imagejpeg($img, $tmp_file);
            }
        }
    }

    public function get_cachefilebase64($post_id) {
        $this->cache_file = $this->cache_dir . 'post-' . $post_id . '.base64';
        if (file_exists($this->cache_file)) {
            return file_get_contents($this->cache_file);
        }
        return false;
    }

    public function set_cachefilebase64($post_id, $base64) {
        $this->cache_file = $this->cache_dir . 'post-' . $post_id . '.base64';
        file_put_contents($this->cache_file, $base64);
    }

    public function get_base64_thumb_value($post_id = false) {
        if ($this->cache_in_file) {
            $base64 = $this->get_cachefilebase64($post_id);
        } else {
            $base64 = get_post_meta($post_id, $this->meta_id, 1);
        }

        if ($this->compress_base64) {
            if ($this->cache_in_file && function_exists('gzencode')) {
                $base64 = gzdecode($base64);
            }
            $base64 = str_replace('#d#', 'data:image/jpeg;base64,/9j/', $base64);
            $base64 = str_replace('#j#', $this->jpeg_prefix, $base64);
        }

        return $base64;
    }

    public function get_base64_thumb($post_id = false) {
        global $post;
        /* Retrieve current post */
        if ($post_id === false) {
            if (!is_object($post)) {
                return false;
            }
            $post_id = $post->ID;
        }

        /* Retrieve thumb code if available */
        $thumbnailcode_id = get_post_meta($post_id, $this->meta_id . '_id', 1);
        $thumbnailcode = $this->get_base64_thumb_value($post_id);

        /* No thumb code, but a thumbnail should exist, regenerate */
        if (!$thumbnailcode && ($thumbnailcode_id > 0 || $thumbnailcode_id == '')) {
            $this->save_post($post_id, true);
            $thumbnailcode = $this->get_base64_thumb_value($post_id);
        }

        return $thumbnailcode;
    }

    /* ----------------------------------------------------------
      Prefix
    ---------------------------------------------------------- */

    public function update_jpeg_prefix() {
        /* Extract common part in base64 strings */
        $datab1 = explode('/', $this->generate_base64_thumb(dirname(__FILE__) . '/images/test.jpg'));
        $this->jpeg_prefix = $datab1[4];
        update_option('wpudbthumbnail_jpegprefix', $prefix);
    }

    /* ----------------------------------------------------------
      Clear
    ---------------------------------------------------------- */

    public function clear_cache() {

        /* Delete post metas */
        delete_post_meta_by_key($this->meta_id);
        delete_post_meta_by_key($this->meta_id . '_id');

        /* Delete cache folder content */
        if (!is_dir($this->cache_dir)) {
            return;
        }
        $files = glob($this->cache_dir . "*.base64");
        foreach ($files as $filename) {
            @unlink($filename);
        }
    }

    /* ----------------------------------------------------------
      Uninstall
    ---------------------------------------------------------- */

    public function uninstall() {

        delete_option('wpudbthumbnail_jpegprefix');

        // Clear cache
        $this->clear_cache();

        // Delete remaining folders
        if (!is_dir($this->cache_dir)) {
            return;
        }
        $files = scandir($this->cache_dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            @unlink($this->cache_dir . $file);
        }

        @rmdir($this->cache_dir);
    }

}

$wpudbthumbnail = new wpudbthumbnail();

function the_wpudbthumbnail($post_id = false) {
    echo get_the_wpudbthumbnail($post_id);
}

function get_the_wpudbthumbnail($post_id = false) {
    global $wpudbthumbnail;
    return $wpudbthumbnail->get_base64_thumb($post_id);
}
