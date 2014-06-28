<?php
defined('ABSPATH') OR exit;
if(!class_exists('Picturefill_WP')){
  class Picturefill_WP{

    public static $wpdb;
    private $model;

    // Setup singleton pattern
    public static function get_instance(){
      static $instance;

      if(null === $instance){
        $instance = new self();
      }

      return $instance;
    }

    private function __clone(){
      return null;
    }

    private function __wakeup(){
      return null;
    }

    public static function set_wpdb(){
      global $wpdb;
      self::$wpdb = $wpdb;
    }

    public static function deactivate(){
      self::clear_picturefill_wp_options();
    }

    public static function clear_picturefill_wp_options(){
      $picturefill_wp_transients = self::$wpdb->get_col('SELECT option_name FROM ' . self::$wpdb->options . ' WHERE option_name LIKE \'%picturefill_wp%\'');
      foreach($picturefill_wp_transients as $transient){
        delete_option($transient);
      }
    }

    public static function clear_picturefill_wp_transients(){
      $picturefill_wp_transients = self::$wpdb->get_col('SELECT option_name FROM ' . self::$wpdb->options . ' WHERE option_name LIKE \'%_picturefill_wp%\'');
      foreach($picturefill_wp_transients as $transient){
        delete_option($transient);
      }
    }

    // Constructor, add actions and filters
    private function __construct(){
      add_action('init', array('Picturefill_WP', 'set_wpdb'));
//      add_action('init', array($this, 'add_image_sizes'));
      add_action('init', array($this, 'add_update_hook'));
      add_action('wp_loaded', array($this, 'set_parent_model'));
      add_action('wp_enqueue_scripts', array($this, 'register_picturefill_scripts'));
      add_filter('the_content', array($this, 'picturefill_wp_apply_to_the_content'), apply_filters('picturefill_wp_the_content_filter_priority', 11));
      add_action('picturefill_wp_updated', array('Picturefill_WP', 'clear_picturefill_wp_transients'));
    }

    // Filter and action methods
    public function set_parent_model(){
      require_once(PICTUREFILL_WP_PATH . 'inc/class-model-application-picturefill-wp.php');
      $this->model = new Model_Application_Picturefill_WP();
      do_action('register_srcset');
    }

    public function register_picturefill_scripts(){
      if(WP_DEBUG){
        wp_register_script('picturefill', PICTUREFILL_WP_URL . 'js/libs/picturefill.js', array(), PICTUREFILL_WP_VERSION, true);
      }else{
        wp_register_script('picturefill', PICTUREFILL_WP_URL . 'js/libs/picturefill.min.js', array(), PICTUREFILL_WP_VERSION, true);
      }
    }

    public function apply_picturefill_wp_to_the_content($html){
      return $this->cache_picturefill_output($html, 'the_content');
    }

    public function cache_picturefill_output($html){
      $html_hash = md5($html);
      $cache_duration = apply_filters('picturefill_wp_cache_duration', 86400);
      $cached_output = get_transient('picturefill_wp_output_' . $html_hash);
      if(!empty($cached_output)){
        wp_enqueue_script('picturefill');
        return $cached_output;
      }else{
        $output = $this->replace_images($html);
        if($output !== $html){
          set_transient('picturefill_wp _output_' . $html_hash, $output, $cache_duration);
        }
        return $output;
      }
    }

    public function replace_images($html){
      do_action('picturefill_wp_before_replace_images');
      require_once(PICTUREFILL_WP_PATH . 'inc/class-model-image-picturefill-wp.php');
      $DOMDocument = Model_Image_Picturefill_WP::get_DOMDocument();
      $images = Model_Image_Picturefill_WP::get_images($DOMDocument, $html);
      if($images->length > 0){
        require_once(PICTUREFILL_WP_PATH . 'inc/class-view-picturefill-wp.php');
        wp_enqueue_script('picturefill');
        $html = View_Picturefill_WP::standardize_img_tags($html);
        foreach($images as $image){
          if('picture' !== $image->parentNode->tagName && !$image->hasAttribute('data-picturefill-wp-ignore') && !$image->hasAttribute('srcset')){
            $model_image_picturefill_wp = new Model_Image_Picturefill_WP($this->model, $DOMDocument, $image);
            $view_picturefill_wp = new View_Picturefill_WP($model_image_picturefill_wp);

            $html = str_replace($view_picturefill_wp->get_original_image(), $view_picturefill_wp->render_template('picture'), $html);
          }elseif($image->hasAttribute('srcset')){
            wp_enqueue_script('picturefill');
          }
        }
      }elseif(true === Model_Image_Picturefill_WP::syntax_present($DOMDocument, $html)){
        wp_enqueue_script('picturefill');
      }
      do_action('picturefill_wp_after_replace_images');
      return apply_filters('picturefill_wp_replace_images_output', $html);
    }

    /*
    public function add_image_sizes(){
      if(apply_filters('picturefill_wp_add_@2x_images', false)){
        add_image_size('thumbnail@2x', get_option('thumbnail_size_w') * 2, get_option('thumbnail_size_h') * 2, get_option('thumbnail_crop'));
        add_image_size('medium@2x', get_option('medium_size_w') * 2, get_option('medium_size_h') * 2, get_option('medium_crop'));
        add_image_size('large@2x', get_option('large_size_w') * 2, get_option('large_size_h') * 2, get_option('large_crop'));
      }
    }
     */

    public function register_srcset($handle, $srcset_array, $attached = array()){
      return $this->model->register_srcset($handle, $srcset_array, $attached);
    }

    public function register_sizes($handle, $sizes_string, $attached = array()){
      return $this->model->register_sizes($handle, $sizes_string, $attached);
    }

    public function add_update_hook(){
      if(get_option('picturefill_wp_version') !== PICTUREFILL_WP_VERSION){
        do_action('picturefill_wp_updated');
        update_option('picturefill_wp_update_timestamp', time());
        update_option('picturefill_wp_version', PICTUREFILL_WP_VERSION);
      }
    }
  }
}
